<?php

namespace MediaWiki\Extension\TimeTracker;

use MediaWiki\Html\Html;
use MediaWiki\Title\TitleFactory;

/**
 * Shared HTML for the time/customer/job tables rendered in PHP: page links,
 * the pencil edit-link, note text, status badges, and the scroll wrapper.
 */
class TableRenderer {

	public function __construct(
		private readonly TitleFactory $titleFactory,
		private readonly EntryWikitext $wikitext
	) {
	}

	/**
	 * Total ordering for entry/row arrays so table rows are deterministic (SMW
	 * returns them unordered): by customer name, then job name, then task name
	 * with the General bucket (empty task) first, then day (most recent first,
	 * when present), then user — with the page ids as final tie-breakers so rows
	 * never shuffle even when two entities share a name. $customers/$jobs/$tasks
	 * map page id -> display name. Each row needs customer/job/task keys and, for
	 * per-day tables, day/user.
	 *
	 * @param array<string,?string> $a
	 * @param array<string,?string> $b
	 * @param array<string,string> $customers
	 * @param array<string,string> $jobs
	 * @param array<string,string> $tasks
	 */
	public static function compareEntries( array $a, array $b, array $customers, array $jobs, array $tasks ): int {
		$name = static fn ( array $map, string $id ): string => $map[$id] ?? $id;
		$ta = (string)( $a['task'] ?? '' );
		$tb = (string)( $b['task'] ?? '' );
		if ( $ta === '' || $tb === '' ) {
			// General (empty task) first; two Generals fall through to later keys.
			$taskCmp = ( $ta === '' ? 0 : 1 ) <=> ( $tb === '' ? 0 : 1 );
		} else {
			$taskCmp = strcasecmp( $name( $tasks, $ta ), $name( $tasks, $tb ) );
		}
		return strcasecmp( $name( $customers, (string)$a['customer'] ), $name( $customers, (string)$b['customer'] ) )
			?: strcasecmp( $name( $jobs, (string)$a['job'] ), $name( $jobs, (string)$b['job'] ) )
			?: $taskCmp
			?: strcmp( (string)( $b['day'] ?? '' ), (string)( $a['day'] ?? '' ) )
			?: strcmp( (string)( $a['user'] ?? '' ), (string)( $b['user'] ?? '' ) )
			?: strcmp( (string)$a['customer'], (string)$b['customer'] )
			?: strcmp( (string)$a['job'], (string)$b['job'] )
			?: strcmp( $ta, $tb );
	}

	/** A link to a page by id, labeled $label; '—' if blank. */
	public function pageLink( string $id, string $label ): string {
		if ( $id === '' ) {
			return '—';
		}
		$title = $this->titleFactory->newFromText( $id );
		return $title
			? Html::element( 'a', [ 'href' => $title->getLocalURL() ], $label !== '' ? $label : $id )
			: htmlspecialchars( $label !== '' ? $label : $id );
	}

	/** A link to a user's page. */
	public function userLink( string $user ): string {
		return $user !== '' ? $this->pageLink( 'User:' . $user, $user ) : '—';
	}

	/**
	 * A pencil linking to Special:EditTime pre-filled for one entry; $returnTo (a
	 * page title) is where Save returns to — the page this table is on.
	 */
	public function editLink( string $customer, string $job, string $task, string $day, string $user, string $returnTo = '' ): string {
		$title = $this->titleFactory->newFromText( 'Special:EditTime' );
		$url = $title ? $title->getLocalURL( self::editQuery( $customer, $job, $task, $day, $user, $returnTo ) ) : '#';
		return Html::element( 'a',
			[ 'href' => $url, 'title' => wfMessage( 'timereports-edit-entry' )->inContentLanguage()->text() ], '✏️' );
	}

	/** The Special:EditTime query params pre-filling one entry (shared by the
	 * entity-page pencils and the reports pencils, which differ only in returnto). */
	public static function editQuery( string $customer, string $job, string $task, string $day, string $user,
		string $returnto = '', string $returntoquery = ''
	): array {
		$q = [ 'customer' => $customer, 'job' => $job, 'day' => $day, 'user' => $user ];
		if ( $task !== '' ) {
			$q['task'] = $task;
		}
		if ( $returnto !== '' ) {
			$q['returnto'] = $returnto;
		}
		if ( $returntoquery !== '' ) {
			$q['returntoquery'] = $returntoquery;
		}
		return $q;
	}

	/** Render a stored note: decode the storage markers, keep line breaks, escape HTML. */
	public function notes( string $stored ): string {
		return nl2br( htmlspecialchars( $this->wikitext->unfoldNewlines( $stored ) ) );
	}

	/** Status pill; the label is localised, the modifier class keeps the raw key. */
	public function statusBadge( string $status ): string {
		$status = strtolower( $status !== '' ? $status : 'active' );
		$msg = wfMessage( 'timetracker-status-' . $status )->inContentLanguage();
		return Html::element( 'span', [ 'class' => 'tt-badge tt-badge-' . $status ],
			$msg->exists() ? $msg->text() : $status );
	}

	/** Wrap a table in the scrollable, bordered container. */
	public function scroll( string $tableHtml ): string {
		return Html::rawElement( 'div', [ 'class' => 'tt-table-scroll' ], $tableHtml );
	}

	/** An empty-state message. */
	public function empty( string $message ): string {
		return Html::element( 'div', [ 'class' => 'tt-empty' ], $message );
	}
}
