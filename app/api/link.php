<?php
declare(strict_types=1);

/** /api/link/* — what a visitor of a public link may do before browsing it. */
final class LinkApi
{
    public static function info(): void
    {
        $l = Api::$link;
        if (!$l) throw new NotFoundException('No link given');
        $state = Api::$linkState;
        $view = Links::publicView($l, $state['password_ok'] && $state['terms_ok'] && Http::method() === 'GET');
        Http::ok($view + ['needs_password' => !$state['password_ok'], 'needs_terms' => !$state['terms_ok'], 'user' => Api::user() ? Users::brief(Api::user()) : null]);
    }

    public static function unlock(): void
    {
        $l = Api::$link;
        if (!$l) throw new NotFoundException('No link given');
        $state = Links::unlock($l, Http::input('password') !== null ? (string) Http::input('password') : null, Http::bool('accept_terms', false));
        Activity::log('link_access', ['owner_id' => (int) $l['owner_id'], 'path' => $l['path'], 'is_dir' => (int) $l['is_dir'], 'client' => 'link', 'details' => ['link_id' => (int) $l['id']]]);
        Api::$linkState = $state;
        Http::ok(Links::publicView($l, true) + ['needs_password' => !$state['password_ok'], 'needs_terms' => !$state['terms_ok']]);
    }
}
