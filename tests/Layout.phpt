<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$dir = TEMP_DIR . '/projekt';
FileSystem::createDir($dir . '/workflows');
FileSystem::write($dir . '/workflows/w.json', '{"name":"w","steps":[]}');

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
