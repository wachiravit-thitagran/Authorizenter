/**
 * Autorizenter editor blocks: login + logout.
 *
 * Both are dynamic (server-rendered via shortcodes), previewed with
 * ServerSideRender and configured through the block inspector. Written without
 * JSX so no build step is required.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var ServerSideRender = serverSideRender; // default export.
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;

	blocks.registerBlockType( 'autorizenter/login', {
		apiVersion: 2,
		title: __( 'Autorizenter Login', 'autorizenter' ),
		description: __( 'SSO sign-in buttons for a login context.', 'autorizenter' ),
		category: 'widgets',
		icon: 'lock',
		attributes: {
			context: { type: 'string', default: 'default' }
		},
		edit: function ( props ) {
			var context = props.attributes.context || 'default';
			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Login settings', 'autorizenter' ) },
						el( TextControl, {
							label: __( 'Context', 'autorizenter' ),
							help: __( 'Login context id, e.g. default or admin.', 'autorizenter' ),
							value: context,
							onChange: function ( value ) {
								props.setAttributes( { context: value } );
							}
						} )
					)
				),
				el( ServerSideRender, {
					key: 'preview',
					block: 'autorizenter/login',
					attributes: props.attributes
				} )
			];
		},
		save: function () {
			return null; // Dynamic block.
		}
	} );

	blocks.registerBlockType( 'autorizenter/logout', {
		apiVersion: 2,
		title: __( 'Autorizenter Logout', 'autorizenter' ),
		description: __( 'Sign-out link (supports SSO logout).', 'autorizenter' ),
		category: 'widgets',
		icon: 'unlock',
		attributes: {
			label: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Logout settings', 'autorizenter' ) },
						el( TextControl, {
							label: __( 'Button label', 'autorizenter' ),
							value: props.attributes.label || '',
							onChange: function ( value ) {
								props.setAttributes( { label: value } );
							}
						} )
					)
				),
				el( ServerSideRender, {
					key: 'preview',
					block: 'autorizenter/logout',
					attributes: props.attributes
				} )
			];
		},
		save: function () {
			return null; // Dynamic block.
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.serverSideRender, window.wp.i18n );
