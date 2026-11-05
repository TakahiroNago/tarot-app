<?php

test('route files emit nothing before the opening PHP tag', function (string $file) {
    expect(file_get_contents(base_path("routes/{$file}")))->toStartWith('<?php');
})->with(['web.php', 'console.php']);

test('loading the web routes writes nothing to the response output', function () {
    ob_start();
    require base_path('routes/web.php');

    expect(ob_get_clean())->toBe('');
});
