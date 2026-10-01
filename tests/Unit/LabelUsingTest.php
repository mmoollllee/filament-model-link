<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

/**
 * `labelUsing` — one list labelling its pills differently from everywhere
 * else: staff see a project's internal name where customers see its public one.
 */
function labelledChain(): PillModel
{
    $project = PillModel::fake(['name' => 'Public name', 'internal' => 'Internal name'], id: 1);
    $scene = PillModel::fake(['name' => 'Crane south'], id: 2);
    $scene->parent = $project;

    return $scene;
}

function internalNames(): Closure
{
    return fn (Model $model): ?string => $model->internal ?? null;
}

it('relabels an ancestor of a chain and leaves the rest alone', function (): void {
    $html = ModelReferencePresenter::renderPillChain(labelledChain(), labelUsing: internalNames());

    expect($html)
        ->toContain('Internal name')
        ->not->toContain('Public name')
        // The scene has no internal name: null keeps its own label.
        ->toContain('Crane south');
});

it('relabels the target too', function (): void {
    $project = PillModel::fake(['name' => 'Public name', 'internal' => 'Internal name'], id: 1);

    expect(ModelReferencePresenter::renderPillChain($project, labelUsing: internalNames()))
        ->toContain('Internal name')
        ->not->toContain('Public name');
});

it('keeps the pill label for a blank answer', function (): void {
    $project = PillModel::fake(['name' => 'Public name'], id: 1);

    expect(ModelReferencePresenter::renderPillChain($project, labelUsing: fn (): string => '  '))
        ->toContain('Public name')
        ->and(ModelReferencePresenter::pillLabelFor($project, fn (): ?string => null))->toBe('Public name')
        ->and(ModelReferencePresenter::pillLabelFor($project))->toBe('Public name');
});

it('carries the labels through several chains and into the overflow pill', function (): void {
    $first = PillModel::fake(['name' => 'Public one', 'internal' => 'Internal one'], id: 1);
    $second = PillModel::fake(['name' => 'Public two', 'internal' => 'Internal two'], id: 2);

    $html = ModelReferencePresenter::renderPillChains([$first, $second], maxPills: 1, labelUsing: internalNames());

    expect($html)
        ->toContain('Internal one')
        // The hidden one is listed in the "+1" pill's title — by the same name.
        ->toContain('title="Internal two"')
        ->not->toContain('Public');
});

it('applies to the plain-text chain as well', function (): void {
    expect(ModelReferencePresenter::textChain(labelledChain(), labelUsing: internalNames()))
        ->toBe('Internal name › Crane south');
});

it('limits a relabelled ancestor like any other', function (): void {
    $scene = labelledChain();
    $scene->parent->internal = 'A very long internal project name';

    expect(ModelReferencePresenter::renderPillChain($scene, ancestorLabelLimit: 10, labelUsing: internalNames()))
        ->toContain('A very lon...')
        ->not->toContain('A very long internal');
});
