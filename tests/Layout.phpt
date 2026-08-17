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

// assety
Assert::contains('/assets/bootstrap.min.css', $html);
Assert::contains('/assets/donut.css', $html);
Assert::contains('/assets/bootstrap.bundle.min.js', $html);
Assert::contains('/assets/rows.js', $html);

// dvousloupcový rám
Assert::contains('container-fluid', $html);
Assert::match('~<main[^>]*class="[^"]*\bcol\b~', $html, 'obsah je pravý sloupec');

// levý sloupec je jeden prvek: pod md offcanvas, od md výš sloupec
Assert::match('~<nav[^>]*class="[^"]*\boffcanvas-md\b~', $html);
Assert::match('~<nav[^>]*class="[^"]*\bcol-md-3\b~', $html);
Assert::contains('data-bs-toggle=offcanvas', $html);
Assert::contains('id=nav', $html);

// obě sekce v navigaci
Assert::contains('>Workflow</a>', $html);
Assert::contains('>Kameny</a>', $html);

// aktivní je ta, na které stojíme — a ta druhá ne
// (Latte vykresluje href z n:href před class z n:class bez ohledu na pořadí
// atributů v šabloně, proto [^>]* i před "class")
Assert::match('~<a[^>]*class="nav-link active"[^>]*>Workflow</a>~', $html, 'Workflow je aktivní');
Assert::notMatch('~<a[^>]*class="nav-link active"[^>]*>Kameny</a>~', $html, 'Kameny aktivní nejsou');

// výchozí drobečky, dokud je stránka nepřepíše (Task 4)
Assert::contains('breadcrumb', $html);

// aktivní položka to musí říct i odečítači obrazovky, ne jen barvou
Assert::match('~<a[^>]*aria-current="page"[^>]*>Workflow</a>~', $html);
Assert::notMatch('~<a[^>]*aria-current="page"[^>]*>Kameny</a>~', $html);

// drobečky přehledu: poslední položka je aktivní (a pro odečítač obrazovky
// nese aria-current) a není odkaz
Assert::match('~<li class="breadcrumb-item active" aria-current=page>Workflow</li>~', $html);

// drobečky detailu: sekce je odkaz, jméno workflow poslední
[, $detail] = runWorkflowPresenterIn($dir, ['action' => 'detail', 'name' => 'w']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Workflow</a></li>~', $detail);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>w</li>~', $detail);

// zpáteční odkazy zmizely — drobečky je nahradily
Assert::notContains('← workflow', $detail);

// drobečky Workflow:edit bez jména: nové workflow, odkaz jen na přehled
[, $newWorkflow] = runWorkflowPresenterIn($dir, ['action' => 'edit']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Workflow</a></li>~', $newWorkflow);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>nové workflow</li>~', $newWorkflow);

// drobečky Workflow:edit se jménem: mezičlánek je odkaz na detail toho workflow
[, $editWorkflow] = runWorkflowPresenterIn($dir, ['action' => 'edit', 'name' => 'w']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">w</a></li>~', $editWorkflow);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>hlavička</li>~', $editWorkflow);

// drobečky Workflow:step: krok je aktivní, před ním odkaz na detail workflow
[, $step] = runWorkflowPresenterIn($dir, ['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">w</a></li>~', $step);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>krok</li>~', $step);

// drobečky Block:default: poslední (jediná) položka je aktivní
[, $blockDefault] = runBlockPresenterIn($dir, ['action' => 'default']);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>Kameny</li>~', $blockDefault);

// na stránce kamene svítí v navigaci „Kameny", a Workflow ne. Dokud testovací
// továrna vracela BlockPresenter pro každé jméno, byla tahle aserce vakuová —
// isLinkCurrent() vracelo true pro obě sekce naráz.
Assert::match('~<a[^>]*class="nav-link active"[^>]*>Kameny</a>~', $blockDefault);
Assert::notMatch('~<a[^>]*class="nav-link active"[^>]*>Workflow</a>~', $blockDefault);
Assert::match('~<a[^>]*aria-current="page"[^>]*>Kameny</a>~', $blockDefault);
Assert::notMatch('~<a[^>]*aria-current="page"[^>]*>Workflow</a>~', $blockDefault);

// drobečky Block:edit: sekce je odkaz, mezičlánek je odkaz na detail kamene,
// poslední položka je "úprava"
[, $blockEdit] = runBlockPresenterIn($dir, ['action' => 'edit', 'name' => 'k']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Kameny</a></li>~', $blockEdit);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">k</a></li>~', $blockEdit);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>úprava</li>~', $blockEdit);

// drobečky Block:detail: sekce je odkaz, jméno kamene poslední
[, $blockDetail] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'k']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Kameny</a></li>~', $blockDetail);
Assert::match('~<li class="breadcrumb-item active" aria-current=page>k</li>~', $blockDetail);
