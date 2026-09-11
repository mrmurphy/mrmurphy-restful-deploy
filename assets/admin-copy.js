/**
 * Copy buttons for the agent brief on the MrMurphy Restful Deploy settings
 * screen.
 *
 * The brief is the point of that screen, and it is long enough that hand-
 * selecting it is annoying and error-prone. Each button copies the read-only
 * field next to it, and says so.
 */
( function () {
	'use strict';

	/**
	 * Put the button's own wording back after a moment.
	 *
	 * @param {HTMLButtonElement} button  Button.
	 * @param {string}            message What to show instead.
	 */
	function flash( button, message ) {
		if ( ! button.dataset.label ) {
			return;
		}

		if ( ! button.dataset.original ) {
			button.dataset.original = button.textContent;
		}

		button.textContent = message;

		window.setTimeout( function () {
			button.textContent = button.dataset.original;
		}, 2000 );
	}

	/**
	 * Copy a field's contents.
	 *
	 * @param {HTMLTextAreaElement} field Field.
	 * @return {Promise<boolean>} Whether it worked.
	 */
	function copy( field ) {
		field.focus();
		field.select();

		// The async clipboard API needs a secure context, so plain http:// (other
		// than localhost) falls through to the selection above plus execCommand.
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( field.value ).then(
				function () {
					return true;
				},
				function () {
					return legacyCopy();
				}
			);
		}

		return Promise.resolve( legacyCopy() );
	}

	/**
	 * The older copy path: whatever is selected.
	 *
	 * @return {boolean} Whether it worked.
	 */
	function legacyCopy() {
		try {
			return document.execCommand( 'copy' );
		} catch ( error ) {
			return false;
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-mrmurphy-copy]' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var field = document.getElementById( button.getAttribute( 'data-mrmurphy-copy' ) );

		if ( ! field ) {
			return;
		}

		copy( field ).then( function ( ok ) {
			flash( button, ok ? button.dataset.copied : button.dataset.failed );
		} );
	} );

	// Clicking into a read-only brief should select all of it, because that is
	// what anyone clicking into it wants.
	document.addEventListener( 'focusin', function ( event ) {
		if ( event.target instanceof HTMLTextAreaElement && event.target.readOnly ) {
			event.target.select();
		}
	} );
}() );
