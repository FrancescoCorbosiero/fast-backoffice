<?php

declare(strict_types=1);

namespace App\Modules\Websites\Contracts;

use Filament\Actions\Action;
use Filament\Schemas\Components\Component;

/**
 * A section of the website page contributed by a module that links to
 * websites (its domains, its repositories, its pipeline…). Register it in
 * the module's provider:
 *
 *     $this->app->tag([DomainsWebsitePanel::class], WebsitePanel::TAG);
 *
 * Like the contact page's ContactPanel: the section brings its own
 * heading and create action, and createAction() feeds the page's "Nuovo"
 * menu.
 */
interface WebsitePanel
{
    public const string TAG = 'websites.panels';

    public function key(): string;

    public function label(): string;

    /**
     * Where the section goes on the page; lower first.
     */
    public function sort(): int;

    /**
     * The whole section for one website, heading included.
     */
    public function component(int $websiteId): Component;

    /**
     * How the owner adds one of these for the website, prefilled with the
     * website and its client; null when they are not created by hand.
     */
    public function createAction(int $websiteId): ?Action;
}
