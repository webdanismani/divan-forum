<?php

namespace Meydan\Example;

use App\Forum\Addons\Addon as BaseAddon;

/** The smallest useful add-on: one page, one line in the footer, one listener. */
class Addon extends BaseAddon
{
    public function boot(): void
    {
        $this->loadViews();          // view('meydan-example::hello')
        $this->loadTranslations();   // lang/tr.json
        $this->loadRoutes();         // routes.php

        // Printed where the layout says @hook('layout.footer').
        $this->hook('layout.footer', fn (array $context) => view('meydan-example::footer'));

        // Forum events carry the model that was just created.
        $this->on('forum.thread.created', function ($thread) {
            logger()->info('Example add-on: new thread "'.$thread->title.'"');
        });
    }
}
