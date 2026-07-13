<?php

use MediaWiki\Extension\TimeTracker\EntryStore;
use MediaWiki\Extension\TimeTracker\EntryWikitext;
use MediaWiki\Extension\TimeTracker\TableRenderer;
use MediaWiki\Extension\TimeTracker\Timer;
use MediaWiki\Extension\TimeTracker\TimerWidget;
use MediaWiki\Extension\TimeTracker\Timezone;
use MediaWiki\Extension\TimeTracker\TimeTrackerQuery;
use MediaWiki\MediaWikiServices;

/** Wires the extension's domain services for injection into pages and hooks. */
return [
	'TimeTracker:EntryWikitext' => static function ( MediaWikiServices $services ): EntryWikitext {
		return new EntryWikitext();
	},

	'TimeTracker:Timezone' => static function ( MediaWikiServices $services ): Timezone {
		return new Timezone( $services->getMainConfig() );
	},

	'TimeTracker:Query' => static function ( MediaWikiServices $services ): TimeTrackerQuery {
		return new TimeTrackerQuery( $services->getConnectionProvider() );
	},

	'TimeTracker:TableRenderer' => static function ( MediaWikiServices $services ): TableRenderer {
		return new TableRenderer(
			$services->getTitleFactory(),
			$services->get( 'TimeTracker:EntryWikitext' )
		);
	},

	'TimeTracker:EntryStore' => static function ( MediaWikiServices $services ): EntryStore {
		return new EntryStore(
			$services->getWikiPageFactory(),
			$services->getTitleFactory(),
			$services->get( 'TimeTracker:EntryWikitext' )
		);
	},

	'TimeTracker:Timer' => static function ( MediaWikiServices $services ): Timer {
		return new Timer(
			$services->getWikiPageFactory(),
			$services->getTitleFactory(),
			$services->get( 'TimeTracker:EntryStore' ),
			$services->get( 'TimeTracker:EntryWikitext' ),
			$services->get( 'TimeTracker:Timezone' )
		);
	},

	'TimeTracker:TimerWidget' => static function ( MediaWikiServices $services ): TimerWidget {
		return new TimerWidget(
			$services->get( 'TimeTracker:Timer' ),
			$services->get( 'TimeTracker:Query' ),
			$services->getTitleFactory(),
			$services->get( 'TimeTracker:Timezone' )
		);
	},
];
