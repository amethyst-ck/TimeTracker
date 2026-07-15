<?php

namespace MediaWiki\Extension\TimeTracker\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\TimeTracker\Duration;
use MediaWiki\Extension\TimeTracker\Timer;
use MediaWiki\Extension\TimeTracker\TimerWidget;
use MediaWiki\Extension\TimeTracker\TimeTrackerQuery;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * action=timetrackerstop — stop the acting user's running timer and report the
 * per-day cell totals it wrote, so the weekly grid can update in place instead
 * of reloading. Also returns the re-rendered idle Start widget for the calling
 * surface (job/task page or picker) to swap for the running card. A user stops
 * their own timer; the bucket comes from the running state, so there are no
 * bucket params to forge.
 */
class ApiStop extends ApiBase {

	public function __construct(
		$mainModule,
		$moduleName,
		private readonly Timer $timer,
		private readonly TimerWidget $widget,
		private readonly TimeTrackerQuery $query,
		private readonly TitleFactory $titleFactory
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	/** @inheritDoc */
	public function execute() {
		if ( !$this->getUser()->isRegistered() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}
		$result = $this->timer->stop( $this->getAuthority() );
		if ( $result === null ) {
			$this->dieWithError( 'timetracker-api-notimer', 'notimer' );
		}

		$cells = [];
		foreach ( $result['days'] as $day => $total ) {
			$cells[] = [
				'day' => $day,
				'hours' => Duration::trim( (float)$total ),
				'display' => $total > 0 ? Duration::hm( (float)$total ) : '',
			];
		}
		ApiResult::setIndexedTagName( $cells, 'cell' );

		$params = $this->extractRequestParams();
		$returnto = trim( (string)$params['returnto'] );
		$returnTo = $returnto !== '' ? $this->titleFactory->newFromText( $returnto ) : null;
		$widgetHtml = $this->widget->renderIdle(
			$this->getContext(), trim( (string)$params['surface'] ),
			$result['job'], $result['task'], $returnTo );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'total' => Duration::trim( (float)$result['total'] ),
			'display' => $result['total'] > 0 ? Duration::hm( (float)$result['total'] ) : '',
			'customer' => $result['customer'],
			'job' => $result['job'],
			'task' => $result['task'],
			'user' => $result['user'],
			'customerName' => $this->query->nameById( $result['customer'] ),
			'jobName' => $this->query->nameById( $result['job'] ),
			'taskName' => $result['task'] !== '' ? $this->query->nameById( $result['task'] ) : '',
			'cells' => $cells,
			'widget' => $widgetHtml,
		] );
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/** @inheritDoc */
	public function needsToken() {
		return 'csrf';
	}

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'surface' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
			'returnto' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
		];
	}
}
