
( function () {
	'use strict';

	var articlePrefix = mw.config.get( 'wgArticlePath' ).replace( '$1', '' );

	function escapeRegExp( str ) {
		return str.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	var rePath = new RegExp(
		escapeRegExp( articlePrefix ) + 'User[_ ]talk:([^#?&/]+)',
		'i'
	);

	var reQuery = /[?&]title=User[_ ]talk:([^#?&/]+)/i;

	var reKeepAsIs = /[?&](?:action=(?!view(?:&|$))|oldid=|diff=|direction=|curid=|redirect=no)/i;

	function extractUsername( href ) {

		if ( href.toLowerCase().indexOf( 'talk' ) === -1 ) {
			return null;
		}

		if ( reKeepAsIs.test( href ) ) {
			return null;
		}

		var decoded;
		try {
			decoded = decodeURIComponent( href );
		} catch ( e ) {
			decoded = href;
		}

		var m = decoded.match( rePath ) || decoded.match( reQuery );
		if ( !m ) {
			return null;
		}

		return m[ 1 ].replace( /_/g, ' ' );
	}

	function rewriteTalkLinks() {
		var anchors = document.querySelectorAll( 'a[href]' );
		for ( var i = 0; i < anchors.length; i++ ) {
			var a        = anchors[ i ];
			var username = extractUsername( a.getAttribute( 'href' ) || '' );
			if ( !username ) {
				continue;
			}

			a.href = mw.util.getUrl( 'Special:NexaBoard/' + username );

			if ( a.textContent.trim().toLowerCase() === 'talk' ) {
				a.textContent = mw.msg( 'nexaboard-link-board' );
			}
		}
	}

	rewriteTalkLinks();

	mw.hook( 'wikipage.content' ).add( rewriteTalkLinks );

}() );
