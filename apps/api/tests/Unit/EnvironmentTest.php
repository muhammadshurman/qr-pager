<?php

it('runs on the locked PHP major and minor', function (): void {
    expect(PHP_MAJOR_VERSION)->toBe(8)
        ->and(PHP_MINOR_VERSION)->toBe(4);
});

it('has every database and cache extension the stack requires', function (): void {
    expect(get_loaded_extensions())
        ->toContain('pdo_pgsql', 'redis', 'mbstring', 'intl', 'bcmath', 'gd');
});
