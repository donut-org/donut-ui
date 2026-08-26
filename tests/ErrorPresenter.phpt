<?php

declare(strict_types=1);

use Donut\Gui\Presentation\Error\ErrorPresenter;
use Nette\Application\BadRequestException;
use Nette\Application\Request;
use Nette\Application\Responses\CallbackResponse;
use Nette\Http\Response as HttpResponse;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


/** @param array<string, mixed> $params */
function runError(array $params): mixed
{
	return (new ErrorPresenter)->run(new Request('Error', Request::FORWARD, $params));
}


// --- a 404 renders a page, with the message that says what was looked for ---

$response = runError(['exception' => new BadRequestException('Workflow "nope" does not exist. Searched in: /p/workflows')]);
Assert::type(CallbackResponse::class, $response);

$httpResponse = new HttpResponse;

\ob_start();
$response->send(new Nette\Http\Request(new Nette\Http\UrlScript('http://localhost/')), $httpResponse);
$html = (string) \ob_get_clean();

Assert::same(404, $httpResponse->getCode());
Assert::contains('Not found', $html);
// A 404 that drops the message would be correct and useless at the same time.
Assert::contains('Workflow &quot;nope&quot; does not exist. Searched in: /p/workflows', $html);
// And a way out of the dead end, because the address bar is where the user
// just went wrong.
Assert::contains('action=default', $html);


// --- the message is escaped, not injected ---

$response = runError(['exception' => new BadRequestException('<script>alert(1)</script>')]);

\ob_start();
$response->send(new Nette\Http\Request(new Nette\Http\UrlScript('http://localhost/')), new HttpResponse);
$html = (string) \ob_get_clean();

Assert::notContains('<script>alert(1)</script>', $html);
Assert::contains('&lt;script&gt;', $html);


// --- an explicit 403 keeps its own code ---

$response = runError(['exception' => new BadRequestException('nope', 403)]);
$httpResponse = new HttpResponse;

\ob_start();
$response->send(new Nette\Http\Request(new Nette\Http\UrlScript('http://localhost/')), $httpResponse);
\ob_end_clean();

Assert::same(403, $httpResponse->getCode());


// --- anything that isn't a 4xx is rethrown, so Tracy still gets it ---
//
// This is the whole reason the presenter is wired with catchExceptions:
// true. Application::run() catches what the error presenter throws and
// rethrows it, so a genuine crash keeps its bluescreen instead of being
// traded for a shrug — in a tool whose user is also its developer, that
// trade is the wrong way round.

$crash = new \RuntimeException('the disk caught fire');
Assert::exception(fn() => runError(['exception' => $crash]), \RuntimeException::class, 'the disk caught fire');

// Reached with no exception at all — a wiring mistake, and it must say so
// rather than render a soothing page.
Assert::exception(fn() => runError([]), \LogicException::class);
