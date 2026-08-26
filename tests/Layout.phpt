<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';
require __DIR__ . '/inc/blockPresenter.php';

$dir = TEMP_DIR . '/projekt';
FileSystem::createDir($dir . '/workflows');
FileSystem::write($dir . '/workflows/w.json', '{"name":"w","steps":[{"type":"set","key":"x","value":"1"}]}');

FileSystem::createDir($dir . '/blocks');
FileSystem::write($dir . '/blocks/k.json', '{"name":"k","command":"echo","args":[]}');

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'default']);

// assets
Assert::contains('/assets/bootstrap.min.css', $html);
Assert::contains('/assets/donut.css', $html);
Assert::contains('/assets/bootstrap.bundle.min.js', $html);
Assert::contains('/assets/rows.js', $html);

// two-column frame
Assert::contains('container-fluid', $html);
Assert::match('~<main[^>]*class="[^"]*\bcol\b~', $html, 'content is the right column');

// left column is one element: offcanvas below md, plain column from md up
Assert::match('~<nav[^>]*class="[^"]*\boffcanvas-md\b~', $html);
Assert::match('~<nav[^>]*class="[^"]*\bcol-md-3\b~', $html);
Assert::contains('data-bs-toggle=offcanvas', $html);
Assert::contains('id=nav', $html);

// both sections are in the nav
Assert::contains('>Workflows</a>', $html);
Assert::contains('>Blocks</a>', $html);

// the one we're standing on is active — and the other one isn't
// (Latte renders href from n:href before class from n:class regardless of
// attribute order in the template, hence [^>]* before "class" too)
Assert::match('~<a[^>]*class="nav-link active"[^>]*>Workflows</a>~', $html, 'Workflows is active');
Assert::notMatch('~<a[^>]*class="nav-link active"[^>]*>Blocks</a>~', $html, 'Blocks is not active');

// default breadcrumbs, until the page overrides them (Task 4)
Assert::contains('breadcrumb', $html);

// the active item has to say so to a screen reader too, not just by color
Assert::match('~<a[^>]*aria-current="page"[^>]*>Workflows</a>~', $html);
Assert::notMatch('~<a[^>]*aria-current="page"[^>]*>Blocks</a>~', $html);

// overview breadcrumbs: the last item is active (and carries aria-current
// for a screen reader) and is not a link
Assert::match('~<li class="breadcrumb-item active" aria-current=page>Workflow</li>~', $html);

// detail breadcrumbs: the section is a link, the workflow name is last
[, $detail] = runWorkflowPresenterIn($dir, ['action' => 'detail', 'name' => 'w']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Workflow</a></li>~', $detail);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>w</li>~', $detail);

// the "back" links are gone — breadcrumbs replaced them
Assert::notContains('← workflow', $detail);

// Workflow:edit breadcrumbs without a name: "nové workflow", link only to the overview
[, $newWorkflow] = runWorkflowPresenterIn($dir, ['action' => 'edit']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Workflow</a></li>~', $newWorkflow);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>nové workflow</li>~', $newWorkflow);

// Workflow:edit breadcrumbs with a name: the middle item links to that
// workflow's detail (to the target, not just the text — the whole point
// breadcrumbs exist for is "there's a path from editing to the detail")
[, $editWorkflow] = runWorkflowPresenterIn($dir, ['action' => 'edit', 'name' => 'w']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*action=detail[^"]*">w</a></li>~', $editWorkflow);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>hlavička</li>~', $editWorkflow);

// Workflow:step breadcrumbs: "krok" is active, preceded by a link to the workflow's detail
[, $step] = runWorkflowPresenterIn($dir, ['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">w</a></li>~', $step);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>krok</li>~', $step);

// Block:default breadcrumbs: the last (only) item is active
[, $blockDefault] = runBlockPresenterIn($dir, ['action' => 'default']);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>Kameny</li>~', $blockDefault);

// on the block page, "Blocks" lights up in the nav, and "Workflows" doesn't.
// While the test factory returned a BlockPresenter for every name, this
// assertion was vacuous — isLinkCurrent() returned true for both sections
// at once.
Assert::match('~<a[^>]*class="nav-link active"[^>]*>Blocks</a>~', $blockDefault);
Assert::notMatch('~<a[^>]*class="nav-link active"[^>]*>Workflows</a>~', $blockDefault);
Assert::match('~<a[^>]*aria-current="page"[^>]*>Blocks</a>~', $blockDefault);
Assert::notMatch('~<a[^>]*aria-current="page"[^>]*>Workflows</a>~', $blockDefault);

// Block:edit breadcrumbs: section is a link, the middle item links to the
// block's detail, last item is "úprava"
[, $blockEdit] = runBlockPresenterIn($dir, ['action' => 'edit', 'name' => 'k']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Kameny</a></li>~', $blockEdit);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*action=detail[^"]*">k</a></li>~', $blockEdit);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>úprava</li>~', $blockEdit);

// Block:detail breadcrumbs: section is a link, the block's name is last
[, $blockDetail] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'k']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Kameny</a></li>~', $blockDetail);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>k</li>~', $blockDetail);

// profile name in the header: the working directory no longer decides which
// set is active, so it's the only thing that tells the user what they're
// actually editing. Must be on both sections — either one can be the first
// place the user lands.
Assert::contains('<code>projekt</code>', $html);
Assert::contains('<code>projekt</code>', $blockDefault);
