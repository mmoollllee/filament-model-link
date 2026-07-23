<?php

declare(strict_types=1);

use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

it('returns plain text keyed by model key', function (): void {
    $a = PillModel::fake(['name' => 'Alpha'], id: 7);
    $b = PillModel::fake(['name' => 'Beta'], id: 9);

    $labels = ModelReferencePresenter::modelSelectLabels(collect([$a, $b]));

    expect($labels)->toBe([7 => 'Alpha', 9 => 'Beta']);
});

it('carries no HTML at all — that is the whole point', function (): void {
    $a = PillModel::fake(['name' => 'Alpha'], id: 1);

    $labels = ModelReferencePresenter::modelSelectLabels(collect([$a]));

    expect($labels[1])
        ->not->toContain('fi-badge')
        ->not->toContain('<span')
        ->not->toContain('<a ');
});

it('honors HasPillLabel by default, matching the pill', function (): void {
    $model = PillModel::fake(['name' => 'Werk-1 (Kran)'], id: 3);

    expect(ModelReferencePresenter::modelSelectLabels(collect([$model]))[3])
        ->toBe(ModelReferencePresenter::basePillLabel($model));
});

it('accepts an attribute name or a callback', function (): void {
    $model = PillModel::fake(['name' => 'Alpha', 'email' => 'a@example.test'], id: 4);

    expect(ModelReferencePresenter::modelSelectLabels(collect([$model]), 'email')[4])
        ->toBe('a@example.test')
        ->and(ModelReferencePresenter::modelSelectLabels(collect([$model]), labelCallback: fn ($m) => strtoupper($m->name))[4])
        ->toBe('ALPHA');
});

it('keys identically to modelSelectOptions so both can label one select', function (): void {
    $models = collect([
        PillModel::fake(['name' => 'Alpha'], id: 7),
        PillModel::fake(['name' => 'Beta'], id: 9),
    ]);

    expect(array_keys(ModelReferencePresenter::modelSelectLabels($models)))
        ->toBe(array_keys(ModelReferencePresenter::modelSelectOptions($models)));
});

it('honors HasPillLabel in modelSelectOptions too — labels must not disagree', function (): void {
    $model = PillModel::fake(['name' => 'Werk-1 (Kran)'], id: 3);

    $option = ModelReferencePresenter::modelSelectOptions(collect([$model]))[3];
    $label = ModelReferencePresenter::modelSelectLabels(collect([$model]))[3];

    expect($option)->toContain($label)
        ->and($label)->toBe(ModelReferencePresenter::basePillLabel($model));
});

it('still accepts an explicit attribute or callback in modelSelectOptions', function (): void {
    $model = PillModel::fake(['name' => 'Alpha', 'email' => 'a@example.test'], id: 4);

    expect(ModelReferencePresenter::modelSelectOptions(collect([$model]), 'email')[4])
        ->toContain('a@example.test')
        ->and(ModelReferencePresenter::modelSelectOptions(collect([$model]), labelCallback: fn ($m) => strtoupper($m->name))[4])
        ->toContain('ALPHA');
});
