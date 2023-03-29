<?php

class BunchPatrolHooks {

	/**
	 * Add a link to Special:BunchPatrol from Special:RecentChanges if the user has
	 * the 'patrol' permission.
	 *
	 * @param int $userId User ID
	 * @param Title $userText User page title
	 * @param array $items Existing tool links
	 */
	public static function onUserToolLinksEdit( $userId, $userText, &$items ) {
		global $wgTitle, $wgUser;
		if (
			$wgTitle->isSpecial( 'Recentchanges' ) && // forgive me Chad, for I have sinned
			$wgUser->isAllowed( 'patrol' ) &&
			$userId != $wgUser->getId() // don't add this link for yourself because you can't BunchPatrol your own edits
		)
		{
			$contribsPage = SpecialPage::getTitleFor( 'BunchPatrol', $userText );
			$items[] = MediaWiki\MediaWikiServices::getInstance()->getLinkRenderer()->makeKnownLink(
				$contribsPage,
				wfMessage( 'bunchpatrol-bunch' )->text()
			);
		}
	}

}
