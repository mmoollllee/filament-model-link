<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink;

use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Fluent pill builder. Wraps `ModelReferencePresenter` so callers can compose
 * a one-off pill without remembering which static method covers their case.
 *
 *     Pill::for($user)
 *         ->icon($user->role?->icon)
 *         ->iconTooltip($user->role?->name)
 *         ->linked()
 *         ->toHtml();
 *
 *     // Or, for a parent-aware chain (Team › Post › Comment):
 *     Pill::chain($post)->toHtml();
 *
 *     // Auto-Htmlable: `{{ Pill::for($u) }}` renders straight in Blade.
 */
final class Pill implements Htmlable
{
    protected ?string $label = null;

    protected string|BackedEnum|null $iconOverride = null;

    protected string $iconTooltip = '';

    protected ?string $url = null;

    protected bool $linked = false;

    protected bool $chain = false;

    protected ?int $labelLimit = null;

    protected ?int $ancestorLabelLimit = null;

    protected ?string $color = null;

    public function __construct(protected ?Model $model = null) {}

    /**
     * Single pill for a model.
     */
    public static function for(Model $model): self
    {
        return new self($model);
    }

    /**
     * Raw pill with no model behind it — value pills like dates, counters, or
     * statuses that should look exactly like model pills. `linked()` and
     * `asChain()` need a model and are ignored; set `url()` explicitly.
     */
    public static function make(string $label): self
    {
        return (new self)->label($label);
    }

    /**
     * Multi-segment chain (HasPillParent walked).
     */
    public static function chain(Model $model): self
    {
        return (new self($model))->asChain();
    }

    public function asChain(bool $chain = true): self
    {
        $this->chain = $chain;

        return $this;
    }

    /**
     * Override the pill's visible label. Default: `HasPillLabel::pillLabel()`,
     * falling back to `$model->name` / `$model->title` / `Class #id`.
     */
    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Override the pill's leading icon. Pass `null` to use the default icon
     * resolved via `ModelReferencePresenter::resolveIconUsing()`.
     */
    public function icon(string|BackedEnum|null $icon): self
    {
        $this->iconOverride = $icon;

        return $this;
    }

    public function iconTooltip(?string $tooltip): self
    {
        $this->iconTooltip = (string) $tooltip;

        return $this;
    }

    /**
     * Override the pill color (a Filament palette name such as `danger`).
     * Beats the model's HasPills color — use it for per-record status colors.
     * On a chain, only the target segment is recolored.
     */
    public function color(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    /**
     * Force a specific URL. Beats `linked()` resolution and any registered
     * custom URL resolvers.
     */
    public function url(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Auto-link the pill to the model's Filament resource if the current
     * user is authorized. No-op when no resource is registered.
     */
    public function linked(bool $linked = true): self
    {
        $this->linked = $linked;

        return $this;
    }

    public function labelLimit(?int $limit): self
    {
        $this->labelLimit = $limit;

        return $this;
    }

    public function ancestorLabelLimit(?int $limit): self
    {
        $this->ancestorLabelLimit = $limit;

        return $this;
    }

    public function toHtml(): string
    {
        if ($this->model === null) {
            return ModelReferencePresenter::renderPill(
                label: $this->label ?? '',
                color: $this->color,
                icon: $this->iconOverride,
                url: $this->url,
                iconTooltip: $this->iconTooltip,
            );
        }

        $url = $this->url
            ?? ($this->linked ? ModelReferencePresenter::urlForRelated($this->model) : null);

        if ($this->iconOverride !== null || $this->iconTooltip !== '') {
            return ModelReferencePresenter::renderPillWithIconOverride(
                model: $this->model,
                icon: $this->iconOverride,
                label: $this->resolveLabel(),
                iconTooltip: $this->iconTooltip,
                url: $url,
                color: $this->color,
            );
        }

        if ($this->chain) {
            return ModelReferencePresenter::renderPillChain(
                related: $this->model,
                url: $url,
                labelLimit: $this->labelLimit,
                ancestorLabelLimit: $this->ancestorLabelLimit,
                targetColor: $this->color,
            );
        }

        return ModelReferencePresenter::renderStandalonePill(
            model: $this->model,
            label: $this->resolveLabel(),
            url: $url,
            color: $this->color,
        );
    }

    public function toHtmlString(): HtmlString
    {
        return new HtmlString($this->toHtml());
    }

    public function __toString(): string
    {
        return $this->toHtml();
    }

    protected function resolveLabel(): string
    {
        if ($this->label !== null && $this->label !== '') {
            return $this->label;
        }

        return $this->model !== null ? ModelReferencePresenter::basePillLabel($this->model) : '';
    }
}
