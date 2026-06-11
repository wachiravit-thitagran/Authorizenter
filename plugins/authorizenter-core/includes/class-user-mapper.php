<?php
/**
 * Maps identities to WordPress users.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an Identity to a WP_User: links by provider+sub, then by verified
 * email, then auto-provisions a new user when allowed.
 */
class User_Mapper {

	const META_LINK_PREFIX = 'authorizenter_link_'; // + provider => sub.

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Org policy.
	 *
	 * @var Org_Policy
	 */
	private $policy;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Settings store.
	 * @param Org_Policy $policy   Org policy.
	 */
	public function __construct( Settings $settings, Org_Policy $policy ) {
		$this->settings = $settings;
		$this->policy   = $policy;
	}

	/**
	 * Resolve (or create) the WP_User for an identity.
	 *
	 * @param Identity   $identity Identity.
	 * @param array|null $context  Optional resolved context; its auto_provision
	 *                             value overrides the global setting when present.
	 * @return \WP_User|\WP_Error
	 */
	public function resolve( Identity $identity, $context = null ) {
		$cfg = $this->settings->get( 'users' );
		if ( is_array( $context ) && array_key_exists( 'auto_provision', $context ) ) {
			$cfg['auto_provision'] = (bool) $context['auto_provision'];
		}

		$provider_cfg = $this->provider_config( $identity->provider );

		// 1. Existing link by provider + subject id.
		$user = $this->find_by_link( $identity->provider, $identity->sub );
		if ( $user ) {
			$this->maybe_update_name( $user, $identity, $provider_cfg );
			return $user;
		}

		// 1b. Link by username (when enabled in provider config).
		if ( ! empty( $provider_cfg['link_by_username'] ) && '' !== $identity->username ) {
			$by_login = get_user_by( 'login', $identity->username );
			if ( $by_login ) {
				$this->store_link( $by_login->ID, $identity );
				$this->maybe_update_name( $by_login, $identity, $provider_cfg );
				return $by_login;
			}
		}

		// 2. Link by verified email.
		if ( ! empty( $cfg['link_by_email'] ) && '' !== $identity->email && $identity->email_verified ) {
			$existing = get_user_by( 'email', $identity->email );
			if ( $existing ) {
				$this->store_link( $existing->ID, $identity );
				$this->maybe_update_name( $existing, $identity, $provider_cfg );
				return $existing;
			}
		}

		// 3. Auto-provision.
		if ( empty( $cfg['auto_provision'] ) ) {
			return new \WP_Error( 'authorizenter_no_account', __( 'No matching account exists and self-registration is disabled.', 'authorizenter' ), array( 'status' => 403 ) );
		}

		return $this->provision( $identity, $cfg );
	}

	/**
	 * Create a new user from an identity.
	 *
	 * @param Identity $identity Identity.
	 * @param array    $cfg      User config.
	 * @return \WP_User|\WP_Error
	 */
	private function provision( Identity $identity, array $cfg ) {
		$email = $identity->email;
		if ( '' === $email ) {
			// Some providers (e.g. LINE without email permission) yield no email.
			$email = $identity->provider . '_' . substr( md5( $identity->sub ), 0, 12 ) . '@users.noreply.invalid';
		}

		$base     = $this->username_from( $identity, $email );
		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			++$i;
		}

		$role = $this->resolve_role( $identity, $cfg );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'display_name' => '' !== $identity->name ? $identity->name : $username,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_user_by( 'id', $user_id );
		$this->store_link( $user_id, $identity );

		if ( '' !== $identity->first_name || '' !== $identity->last_name ) {
			wp_update_user(
				array(
					'ID'         => $user_id,
					'first_name' => $identity->first_name,
					'last_name'  => $identity->last_name,
				)
			);
		}

		/**
		 * Fires when a new user is provisioned via Authorizenter.
		 *
		 * @param \WP_User $user     New user.
		 * @param Identity $identity Source identity.
		 */
		do_action( 'authorizenter_user_provisioned', $user, $identity );

		// Keep the approved list clean: now that a WordPress account exists, drop
		// the email from the approved list (the existing-account bypass covers it).
		( new Access_List( $this->settings ) )->release_after_provision( $email );

		return $user;
	}

	/**
	 * Resolve the role for a new user from the configured role map.
	 *
	 * Each rule is a matcher mapped to a role; the first match wins, else the
	 * default role is used. Condition types: domain:, provider:, email:, username:,
	 * regex: / email_regex: (pattern vs full email), local: (pattern vs the part
	 * before "@"), or "*". Build boolean expressions with standard precedence:
	 * parentheses "()" highest, then "!" (NOT), "&&" (AND), "||" (OR) lowest. Quote
	 * an atom whose value contains operator characters, e.g.
	 * "( provider:oidc && "local:^(\d{10}|\d{13})$" ) || domain:alumni.example.org".
	 *
	 * @param Identity $identity Identity.
	 * @param array    $cfg      User config.
	 * @return string
	 */
	public function resolve_role( Identity $identity, array $cfg ) {
		$default = isset( $cfg['default_role'] ) && '' !== $cfg['default_role'] ? $cfg['default_role'] : 'subscriber';
		$map     = isset( $cfg['role_map'] ) && is_array( $cfg['role_map'] ) ? $cfg['role_map'] : array();

		$role = $default;
		foreach ( $map as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['match'] ) || empty( $rule['role'] ) ) {
				continue;
			}
			if ( $this->role_match( (string) $rule['match'], $identity ) ) {
				$role = sanitize_key( $rule['role'] );
				break;
			}
		}

		// A role assigned to this specific email on the approved list (Authorizer
		// style) is the admin's explicit choice, so it wins over the role map.
		$approved_role = $this->approved_role_for( $identity->email );
		if ( '' !== $approved_role ) {
			$role = $approved_role;
		}

		/**
		 * Filter the role assigned to a newly provisioned user.
		 *
		 * @param string   $role     Resolved role.
		 * @param Identity $identity Identity.
		 */
		return apply_filters( 'authorizenter_provision_role', $role, $identity );
	}

	/**
	 * Role assigned to a specific email on the approved list, if any.
	 *
	 * @param string $email Email.
	 * @return string Role slug, or '' when none is set.
	 */
	private function approved_role_for( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return '';
		}
		$access = $this->settings->get( 'access' );
		$roles  = isset( $access['approved_roles'] ) && is_array( $access['approved_roles'] ) ? $access['approved_roles'] : array();
		return isset( $roles[ $email ] ) ? sanitize_key( $roles[ $email ] ) : '';
	}

	/**
	 * Whether a role-map matcher applies to an identity.
	 *
	 * @param string   $matcher  Matcher string.
	 * @param Identity $identity Identity.
	 * @return bool
	 */
	private function role_match( $matcher, Identity $identity ) {
		$matcher = trim( (string) $matcher );
		if ( '' === $matcher ) {
			return false;
		}
		if ( '*' === $matcher ) {
			return true;
		}

		$tokens = $this->tokenize_rule( $matcher );
		if ( null === $tokens || array() === $tokens ) {
			return false;
		}

		$pos         = 0;
		$result      = $this->eval_or( $tokens, $pos, $identity );
		$token_count = count( $tokens );

		// Reject malformed expressions (leftover or unbalanced tokens).
		if ( $pos !== $token_count ) {
			return false;
		}
		return $result;
	}

	/**
	 * Tokenize a role-map boolean expression.
	 *
	 * Operators (standard precedence): "(" ")" highest, then "!" (NOT), "&&" (AND),
	 * "||" (OR) lowest. Atoms are "type:value"; wrap an atom in double quotes when
	 * its value contains operator characters, e.g. a regex with parentheses or
	 * alternation: "local:^(\d{10}|\d{13})$".
	 *
	 * @param string $s Expression.
	 * @return array[]|null Token list, or null when malformed (unterminated quote).
	 */
	private function tokenize_rule( $s ) {
		$tokens = array();
		$i      = 0;
		$n      = strlen( $s );

		while ( $i < $n ) {
			$c = $s[ $i ];

			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}
			if ( '(' === $c || ')' === $c || '!' === $c ) {
				$tokens[] = array( 'op', $c );
				++$i;
				continue;
			}
			if ( '&' === $c && $i + 1 < $n && '&' === $s[ $i + 1 ] ) {
				$tokens[] = array( 'op', '&&' );
				$i       += 2;
				continue;
			}
			if ( '|' === $c && $i + 1 < $n && '|' === $s[ $i + 1 ] ) {
				$tokens[] = array( 'op', '||' );
				$i       += 2;
				continue;
			}
			if ( '"' === $c ) {
				++$i;
				$val = '';
				while ( $i < $n && '"' !== $s[ $i ] ) {
					if ( '\\' === $s[ $i ] && $i + 1 < $n && ( '"' === $s[ $i + 1 ] || '\\' === $s[ $i + 1 ] ) ) {
						$val .= $s[ $i + 1 ];
						$i   += 2;
					} else {
						$val .= $s[ $i ];
						++$i;
					}
				}
				if ( $i >= $n ) {
					return null; // Unterminated quote.
				}
				++$i; // Skip closing quote.
				$tokens[] = array( 'atom', $val );
				continue;
			}

			// Unquoted atom: read up to the next operator character or whitespace.
			$val = '';
			while ( $i < $n ) {
				$c = $s[ $i ];
				if ( ctype_space( $c ) || '(' === $c || ')' === $c || '!' === $c ) {
					break;
				}
				if ( '&' === $c && $i + 1 < $n && '&' === $s[ $i + 1 ] ) {
					break;
				}
				if ( '|' === $c && $i + 1 < $n && '|' === $s[ $i + 1 ] ) {
					break;
				}
				$val .= $c;
				++$i;
			}
			$val = trim( $val );
			if ( '' !== $val ) {
				$tokens[] = array( 'atom', $val );
			}
		}

		return $tokens;
	}

	/**
	 * Evaluate: or := and ( "||" and )*.
	 *
	 * @param array[]  $tokens   Tokens.
	 * @param int      $pos      Cursor (by reference).
	 * @param Identity $identity Identity.
	 * @return bool
	 */
	private function eval_or( $tokens, &$pos, Identity $identity ) {
		$value       = $this->eval_and( $tokens, $pos, $identity );
		$token_count = count( $tokens );
		while ( $pos < $token_count && array( 'op', '||' ) === $tokens[ $pos ] ) {
			++$pos;
			// Function first so the cursor always advances (no short-circuit skip).
			$value = $this->eval_and( $tokens, $pos, $identity ) || $value;
		}
		return $value;
	}

	/**
	 * Evaluate: and := not ( "&&" not )*.
	 *
	 * @param array[]  $tokens   Tokens.
	 * @param int      $pos      Cursor (by reference).
	 * @param Identity $identity Identity.
	 * @return bool
	 */
	private function eval_and( $tokens, &$pos, Identity $identity ) {
		$value       = $this->eval_not( $tokens, $pos, $identity );
		$token_count = count( $tokens );
		while ( $pos < $token_count && array( 'op', '&&' ) === $tokens[ $pos ] ) {
			++$pos;
			$value = $this->eval_not( $tokens, $pos, $identity ) && $value;
		}
		return $value;
	}

	/**
	 * Evaluate: not := "!" not | primary.
	 *
	 * @param array[]  $tokens   Tokens.
	 * @param int      $pos      Cursor (by reference).
	 * @param Identity $identity Identity.
	 * @return bool
	 */
	private function eval_not( $tokens, &$pos, Identity $identity ) {
		if ( $pos < count( $tokens ) && array( 'op', '!' ) === $tokens[ $pos ] ) {
			++$pos;
			return ! $this->eval_not( $tokens, $pos, $identity );
		}
		return $this->eval_primary( $tokens, $pos, $identity );
	}

	/**
	 * Evaluate: primary := "(" or ")" | atom.
	 *
	 * @param array[]  $tokens   Tokens.
	 * @param int      $pos      Cursor (by reference).
	 * @param Identity $identity Identity.
	 * @return bool
	 */
	private function eval_primary( $tokens, &$pos, Identity $identity ) {
		if ( $pos >= count( $tokens ) ) {
			return false;
		}
		$token = $tokens[ $pos ];

		if ( array( 'op', '(' ) === $token ) {
			++$pos;
			$value = $this->eval_or( $tokens, $pos, $identity );
			if ( $pos < count( $tokens ) && array( 'op', ')' ) === $tokens[ $pos ] ) {
				++$pos;
			} else {
				$pos = count( $tokens ) + 1; // Force a malformed result.
			}
			return $value;
		}

		if ( 'atom' === $token[0] ) {
			++$pos;
			return $this->role_condition( $token[1], $identity );
		}

		// Unexpected operator where a primary was expected.
		$pos = count( $tokens ) + 1;
		return false;
	}

	/**
	 * Evaluate a single "type:value" role-map condition.
	 *
	 * Types: provider, email, username, domain (exact/subdomain), regex / email_regex
	 * (pattern against the full email), local (pattern against the part before "@"),
	 * or "*". Regex patterns are matched case-sensitively — add "(?i)" for ci.
	 *
	 * @param string   $condition Single condition string.
	 * @param Identity $identity  Identity.
	 * @return bool
	 */
	private function role_condition( $condition, Identity $identity ) {
		$condition = trim( $condition );
		if ( '' === $condition ) {
			return false;
		}
		if ( '*' === $condition ) {
			return true;
		}

		$parts = explode( ':', $condition, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		$type  = strtolower( trim( $parts[0] ) );
		$value = trim( $parts[1] ); // Case preserved; lowered per-type where needed.

		$email = $identity->email;
		$local = ( '' !== $email && false !== strpos( $email, '@' ) ) ? strstr( $email, '@', true ) : $email;

		switch ( $type ) {
			case 'provider':
				return strtolower( $value ) === $identity->provider;
			case 'email':
				return strtolower( $value ) === $email;
			case 'username':
				return '' !== $identity->username && $value === $identity->username;
			case 'domain':
				$value  = strtolower( $value );
				$domain = $identity->email_domain();
				return '' !== $domain && ( $domain === $value || substr( $domain, - ( strlen( $value ) + 1 ) ) === '.' . $value );
			case 'regex':
			case 'email_regex':
				return $this->regex_match( $value, $email );
			case 'local':
				return $this->regex_match( $value, (string) $local );
			default:
				return false;
		}
	}

	/**
	 * Safely test a user-supplied regex (no delimiters) against a subject.
	 *
	 * @param string $pattern Pattern without delimiters (e.g. ^\d{10}$).
	 * @param string $subject Subject string.
	 * @return bool
	 */
	private function regex_match( $pattern, $subject ) {
		if ( '' === $pattern || '' === (string) $subject ) {
			return false;
		}
		$regex  = '#' . str_replace( '#', '\\#', $pattern ) . '#u';
		$result = @preg_match( $regex, $subject ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return 1 === $result;
	}

	/**
	 * Find a user previously linked to this provider+sub.
	 *
	 * @param string $provider Provider id.
	 * @param string $sub      Subject id.
	 * @return \WP_User|null
	 */
	private function find_by_link( $provider, $sub ) {
		if ( '' === $sub ) {
			return null;
		}
		$users = get_users(
			array(
				'meta_key'   => self::META_LINK_PREFIX . $provider, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'all',
			)
		);
		return $users ? $users[0] : null;
	}

	/**
	 * Persist the provider link on the user.
	 *
	 * @param int      $user_id  User id.
	 * @param Identity $identity Identity.
	 * @return void
	 */
	private function store_link( $user_id, Identity $identity ) {
		update_user_meta( $user_id, self::META_LINK_PREFIX . $identity->provider, $identity->sub );
	}

	/**
	 * Derive a username candidate.
	 *
	 * @param Identity $identity Identity.
	 * @param string   $email    Email.
	 * @return string
	 */
	private function username_from( Identity $identity, $email ) {
		$local = strstr( $email, '@', true );
		$base  = sanitize_user( $local ? $local : ( $identity->provider . '_' . $identity->sub ), true );
		$base  = $base ? $base : 'user_' . substr( md5( $identity->sub . $identity->provider ), 0, 8 );
		return $base;
	}

	/**
	 * Read raw provider config by provider id.
	 *
	 * @param string $provider Provider id.
	 * @return array
	 */
	private function provider_config( $provider ) {
		$providers = $this->settings->get( 'providers' );
		return isset( $providers[ $provider ] ) && is_array( $providers[ $provider ] )
			? $providers[ $provider ]
			: array();
	}

	/**
	 * Update first/last name of an existing user based on provider name_update policy.
	 *
	 * @param \WP_User $user         Resolved user.
	 * @param Identity $identity     Identity.
	 * @param array    $provider_cfg Provider config.
	 * @return void
	 */
	private function maybe_update_name( \WP_User $user, Identity $identity, array $provider_cfg ) {
		$mode = isset( $provider_cfg['name_update'] ) ? $provider_cfg['name_update'] : 'none';
		if ( 'none' === $mode || ( '' === $identity->first_name && '' === $identity->last_name ) ) {
			return;
		}
		$update = array( 'ID' => $user->ID );
		if ( 'always' === $mode ) {
			$update['first_name'] = $identity->first_name;
			$update['last_name']  = $identity->last_name;
		} elseif ( 'if_empty' === $mode ) {
			if ( '' === get_user_meta( $user->ID, 'first_name', true ) ) {
				$update['first_name'] = $identity->first_name;
			}
			if ( '' === get_user_meta( $user->ID, 'last_name', true ) ) {
				$update['last_name'] = $identity->last_name;
			}
		}
		if ( count( $update ) > 1 ) {
			wp_update_user( $update );
		}
	}
}
