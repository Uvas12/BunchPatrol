<?php

class BunchPatrol extends SpecialPage {

	public function __construct() {
		parent::__construct( 'BunchPatrol' );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Render the table into the OutputPage.
	 *
	 * @param \Wikimedia\Rdbms\IDatabase $dbr Read-only database handle
	 * @param string $target Target user whose edits to patrol
	 * @param bool $readOnly Are we in read-only mode or not? [FIXME: never called w/ a non-false value...]
	 */
	private function writeBunchPatrolTableContent( &$dbr, $target, $readOnly ) {
		$out = $this->getOutput();

		// Quick sanity check before proceeding...
		$targetUser = User::newFromName( $target );
		if ( !$targetUser ) {
			$out->addWikiMsg( 'nosuchusershort', $target );
			return;
		}

		$linkRenderer = $this->getLinkRenderer();

		$out->addHTML( '<table width="100%" align="center" class="bunchtable"><tr>' );
		if ( !$readOnly ) {
			$out->addHTML( '<td><b>' . $this->msg( 'bunchpatrol-patrol' )->escaped() . '</b></td>' );
		}

		$out->addHTML( '<td align="center"><b>' . $this->msg( 'bunchpatrol-diff' )->escaped() . '</b></td></tr>' );

		$opts = [
			'rc_actor' => $targetUser->getActorId(),
			'rc_patrolled' => 0
		];
		$opts[] = ' (rc_namespace = 2 OR rc_namespace = 3) ';

		$res = $dbr->select(
			'recentchanges',
			[
				'rc_id', 'rc_title', 'rc_namespace', 'rc_this_oldid',
				'rc_cur_id', 'rc_last_oldid'
			],
			$opts,
			__METHOD__,
			[ 'LIMIT' => 15 ]
		);

		$count = 0;
		foreach ( $res as $row ) {
			$t = Title::makeTitle( $row->rc_namespace, $row->rc_title );
			$diff = $row->rc_this_oldid;
			$rcid = $row->rc_id;
			$oldid = $row->rc_last_oldid;
			$de = new DifferenceEngine( $t, $oldid, $diff, $rcid );
			$out->addHTML( '<tr>' );
			if ( !$readOnly ) {
				$out->addHTML( "<td valign='middle' style='padding-right:24px; border-right: 1px solid #eee;'><input type='checkbox' name='rc_{$rcid}' /></td>" );
				$out->addHTML( Html::hidden( 'wpBunchPatrolToken', $this->getUser()->getEditToken() ) );
			}
			$out->addHTML( '<td style="border-top: 1px solid #eee;">' );
			$out->addHTML( $linkRenderer->makeLink( $t ) );
			$de->showDiffPage( true );
			$out->addHTML( '</td></tr>' );
			$count++;
		}

		$out->addHTML( '</table><br /><br />' );
		return $count;
	}

	/**
	 * Show the special page
	 *
	 * @param string|null $par Name of the user whose edits you want to bunch-patrol
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		$this->setHeaders();

		$target = $par ?? $request->getVal( 'target' );

		if ( $target == $this->getUser()->getName() ) {
			$out->addHTML( $this->msg( 'bunchpatrol-no-self-patrol' )->escaped() );
			return;
		}

		$dbr = wfGetDB( DB_REPLICA );
		$linkRenderer = $this->getLinkRenderer();
		$me = $this->getPageTitle();

		$unpatrolled = $dbr->selectField(
			'recentchanges',
			'COUNT(*)',
			[ 'rc_patrolled' => 0 ],
			__METHOD__
		);

		if ( !strlen( $target ) ) {
			$restrict = ' AND (rc_namespace = 2 OR rc_namespace = 3) ';
			$res = $dbr->query(
				"SELECT rc_actor, COUNT(*) AS C
					FROM {$dbr->tableName( 'recentchanges' )}
					WHERE rc_patrolled=0
					{$restrict}
					GROUP BY rc_actor
					HAVING C > 2
					ORDER BY C DESC",
				__METHOD__
			);

			$resCnt = $dbr->numRows( $res );
			if ( $resCnt > 500 ) {
				$out->addHTML( '<table width="85%" align="center">' );
				foreach ( $res as $row ) {
					$u = User::newFromActorId( $row->rc_actor );
					if ( $u ) {
						$bpLink = SpecialPage::getTitleFor( 'BunchPatrol', $u->getName() );
						$c = (int)$row->C;
						$out->addHTML(
							'<tr><td>' . $linkRenderer->makeLink( $bpLink, $u->getName() ) .
							"</td><td>{$c}</td>"
						);
					}
				}
				$out->addHTML( '</table>' );
			} else {
				$out->addWikiMsg( 'bunchpatrol-unpatrolled-limit', $resCnt );
			}
			return;
		}

		if ( $request->wasPosted() && $user->isAllowed( 'patrol' ) ) {
			if ( !$user->matchEditToken( $request->getVal( 'wpBunchPatrolToken' ) ) ) {
				$out->addHTML( $this->msg( 'sessionfailure' )->parse() );
				return;
			}

			$values = $request->getValues();
			$vals = [];

			foreach ( $values as $key => $value ) {
				if ( strpos( $key, 'rc_' ) === 0 && $value == 'on' ) {
					$vals[] = str_replace( 'rc_', '', $key );
				}
			}

			foreach ( $vals as $val ) {
				RecentChange::markPatrolled( $val );
				PatrolLog::record( $val, false );
			}

			$restrict = ' AND (rc_namespace = 2 OR rc_namespace = 3) ';
			$res = $dbr->query(
				"SELECT rc_actor, COUNT(*) AS C
					FROM {$dbr->tableName( 'recentchanges' )}
					WHERE rc_patrolled=0
					{$restrict}
					GROUP BY rc_actor
					HAVING C > 2
					ORDER BY C DESC",
				__METHOD__
			);

			$out->addHTML( '<table width="85%" align="center">' );
			foreach ( $res as $row ) {
				$u = User::newFromActorId( $row->rc_actor );
				if ( $u ) {
					$matchCount = (int)$row->C;
					$out->addHTML(
						'<tr><td>' . $linkRenderer->makeLink(
							$me,
							$u->getName(),
							[],
							[ 'target' => $u->getName() ]
						) . "</td><td>{$matchCount}</td>"
					);
				}
			}
			$out->addHTML( '</table>' );
			return;
		}

		// don't show main namespace edits if there are < 500 total unpatrolled edits
		// $target = str_replace( '-', ' ', $target ); // @todo FIXME: wH-ism

		$out->addHTML(
			Html::openElement( 'form', [
				'method' => 'post',
				'name' => 'checkform',
				'action' => $me->getFullURL()
			] ) .
			Html::hidden( 'target', $target )
		);

		if ( in_array( 'sysop', $this->getUser()->getEffectiveGroups() ) ) { // @todo FIXME
			$out->addModules( 'ext.bunchpatrol.scripts' );
			$out->addHTML(
				$this->msg( 'bunchpatrol-select' )->escaped() .
				$this->msg( 'word-separator' )->escaped() .
				Html::input( 'wpCheckAll', $this->msg( 'bunchpatrol-all' )->plain(), 'button' ) .
				Html::input( 'wpCheckNone', $this->msg( 'bunchpatrol-none' )->plain(), 'button' )
			);
		}

		$count = $this->writeBunchPatrolTableContent( $dbr, $target, false );

		if ( $count > 0 ) {
			$out->addHTML( Xml::submitButton( $this->msg( 'bunchpatrol-submit-btn' )->plain() ) );
		}
		$out->addHTML( '</form>' );

		$out->setPageTitle( $this->msg( 'bunchpatrol' ) );

		if ( $count == 0 ) {
			$out->addWikiMsg( 'bunchpatrol-no-unpatrolled-edits', $target );
		}
	}

}