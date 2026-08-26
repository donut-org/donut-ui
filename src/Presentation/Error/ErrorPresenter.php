<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Error;

use Nette\Application\BadRequestException;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\CallbackResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;


/**
 * The page behind a 404.
 *
 * Only 4xx belongs here. Anything else is rethrown, and that is deliberate:
 * Application::run() catches what the error presenter throws and rethrows it,
 * so a genuine crash reaches Tracy's bluescreen exactly as it did before this
 * presenter existed. Rendering our own page for those would trade the stack
 * trace for a shrug — the wrong way round in a tool whose user is also its
 * developer.
 *
 * Plain HTML rather than a Latte template: this page has to render while the
 * application is already failing, and every dependency it takes is one more
 * thing that can be broken at exactly that moment.
 */
final class ErrorPresenter implements IPresenter
{
	public function run(Request $request): Response
	{
		$exception = $request->getParameter('exception');

		if (!$exception instanceof BadRequestException) {
			throw $exception instanceof \Throwable
				? $exception
				: new \LogicException('The error presenter was reached without an exception.');
		}

		$code = $exception->getHttpCode() ?: IResponse::S404_NotFound;
		$message = $exception->getMessage();

		return new CallbackResponse(
			function (IRequest $httpRequest, IResponse $httpResponse) use ($code, $message): void {
				$httpResponse->setCode($code);
				$httpResponse->setContentType('text/html', 'UTF-8');

				echo self::page($code, $message);
			},
		);
	}


	private static function page(int $code, string $message): string
	{
		$title = $code === IResponse::S404_NotFound ? 'Not found' : 'Request failed';

		// The message names what was looked for and where. Dropping it would
		// make the answer correct and useless at the same time — and it comes
		// from the URL, so it is escaped rather than trusted.
		$escaped = \htmlspecialchars($message, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

		return <<<HTML
			<!DOCTYPE html>
			<html lang=en>
			<meta charset=utf-8>
			<meta name=viewport content="width=device-width, initial-scale=1">
			<title>{$title} — Donut</title>
			<link rel=stylesheet href="/assets/donut.css">
			<div class="container py-5">
				<h1>{$title}</h1>
				<p class="alert alert-danger">{$escaped}</p>
				<p><a href="?presenter=Workflow&amp;action=default">Workflows</a>
					&middot; <a href="?presenter=Block&amp;action=default">Blocks</a></p>
			</div>
			HTML;
	}
}
