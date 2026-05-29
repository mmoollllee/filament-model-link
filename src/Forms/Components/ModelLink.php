<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Forms\Components;

use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;

/**
 * Renders a related model as a colored pill chain (icon + label) with an
 * optional link. Use inside Filament form schemas — typically in modal detail
 * views or `Infolist`-like Sections.
 *
 *     ModelLink::make('team.name')->label('Team')
 */
class ModelLink extends Placeholder
{
    /**
     * Null defers to config `default_view_types` at resolution time.
     *
     * @var iterable<int, string>|null
     */
    protected ?iterable $viewTypes = null;

    /**
     * @param  iterable<int, string>  $viewTypes
     */
    public function viewTypes(iterable $viewTypes): static
    {
        $this->viewTypes = $viewTypes;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->content(function ($record) {
            if (! $record) {
                return null;
            }

            $related = ModelReferencePresenter::resolveRelatedRecord($record, $this->getName());

            if (! $related) {
                return null;
            }

            return new HtmlString(ModelReferencePresenter::renderPillChain($related, $this->buildUrl($record)));
        });
    }

    protected function buildUrl(mixed $record): ?string
    {
        return ModelReferencePresenter::url($record, $this->getName(), $this->viewTypes);
    }
}
