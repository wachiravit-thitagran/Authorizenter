<?php
/**
 * Tests for the Identity value object.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Identity;
use PHPUnit\Framework\TestCase;

class IdentityTest extends TestCase {

	public function test_email_is_lowercased_and_trimmed(): void {
		$id = new Identity( 'google', array( 'email' => '  User@PSU.AC.TH ' ) );
		$this->assertSame( 'user@psu.ac.th', $id->email );
	}

	public function test_email_domain(): void {
		$id = new Identity( 'google', array( 'email' => 'student@sci.psu.ac.th' ) );
		$this->assertSame( 'sci.psu.ac.th', $id->email_domain() );
	}

	public function test_email_domain_empty_when_no_email(): void {
		$id = new Identity( 'line', array() );
		$this->assertSame( '', $id->email_domain() );
	}

	public function test_hd_is_lowercased(): void {
		$id = new Identity( 'google', array( 'hd' => 'PSU.AC.TH' ) );
		$this->assertSame( 'psu.ac.th', $id->hd );
	}

	public function test_email_verified_is_boolean(): void {
		$truthy = new Identity( 'google', array( 'email_verified' => 'yes' ) );
		$falsy  = new Identity( 'google', array( 'email_verified' => 0 ) );
		$this->assertTrue( $truthy->email_verified );
		$this->assertFalse( $falsy->email_verified );
	}

	public function test_defaults_and_raw(): void {
		$id = new Identity( 'facebook', array( 'sub' => '42', 'raw' => array( 'x' => 1 ) ) );
		$this->assertSame( 'facebook', $id->provider );
		$this->assertSame( '42', $id->sub );
		$this->assertSame( '', $id->email );
		$this->assertFalse( $id->email_verified );
		$this->assertSame( array( 'x' => 1 ), $id->raw );
	}
}
