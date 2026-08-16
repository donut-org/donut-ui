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

// drobečky přehledu: poslední položka je aktivní a není odkaz
Assert::match('~<li class="breadcrumb-item active">Workflow</li>~', $html);

// drobečky detailu: sekce je odkaz, jméno workflow poslední
[, $detail] = runWorkflowPresenterIn($dir, ['action' => 'detail', 'name' => 'w']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Workflow</a></li>~', $detail);
Assert::match('~<li class="breadcrumb-item active">w</li>~', $detail);

// zpáteční odkazy zmizely — drobečky je nahradily
Assert::notContains('← workflow', $detail);

// drobečky Workflow:edit bez jména: nové workflow, odkaz jen na přehled
[, $newWorkflow] = runWorkflowPresenterIn($dir, ['action' => 'edit']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Workflow</a></li>~', $newWorkflow);
Assert::match('~<li class="breadcrumb-item active">nové workflow</li>~', $newWorkflow);

// drobečky Workflow:edit se jménem: mezičlánek je odkaz na detail toho workflow
[, $editWorkflow] = runWorkflowPresenterIn($dir, ['action' => 'edit', 'name' => 'w']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">w</a></li>~', $editWorkflow);
Assert::match('~<li class="breadcrumb-item active">hlavička</li>~', $editWorkflow);

// drobečky Workflow:step: krok je aktivní, před ním odkaz na detail workflow
[, $step] = runWorkflowPresenterIn($dir, ['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">w</a></li>~', $step);
Assert::match('~<li class="breadcrumb-item active">krok</li>~', $step);

// drobečky Block:default: poslední (jediná) položka je aktivní
[, $blockDefault] = runBlockPresenterIn($dir, ['action' => 'default']);
Assert::match('~<li class="breadcrumb-item active">Kameny</li>~', $blockDefault);

// drobečky Block:edit: sekce je odkaz, jméno kamene poslední
[, $blockEdit] = runBlockPresenterIn($dir, ['action' => 'edit', 'name' => 'k']);
Assert::match('~<li class=breadcrumb-item><a href="[^"]*">Kameny</a></li>~', $blockEdit);
Assert::match('~<li class="breadcrumb-item active">k</li>~', $blockEdit);
