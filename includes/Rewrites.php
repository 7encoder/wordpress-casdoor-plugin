<?php

// ABSPATH prevent public user to directly access your .php files through URL.
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Class Rewrites
 *
 */
class Rewrites
{
    public function create_rewrite_rules($rules): array
    {
        global $wp_rewrite;
        $newRule  = ['auth/(.+)' => 'index.php?auth=' . $wp_rewrite->preg_index(1)];
        $newRules = $newRule + $rules;

        return $newRules;
    }

    public function add_query_vars($qvars): array
    {
        $qvars[] = 'auth';
        $qvars[] = 'code';
        $qvars[] = 'message';
        return $qvars;
    }

    public function flush_rewrite_rules()
    {
        global $wp_rewrite;
        $wp_rewrite->flush_rules();
    }

    public function template_redirect_intercept(): void
    {
        // Redirect unauthenticated users from /my-account/ (and sub-pages)
        // to the WordPress login page, returning them to the original sub-page upon login.
        $req_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (!is_user_logged_in() && $req_uri !== '') {
            $path = parse_url($req_uri, PHP_URL_PATH) ?: '/';
            // Normalize path to begin with a single leading slash
            $path = '/' . ltrim($path, '/');

            // Match /my-account and any sub-page e.g., /my-account/edit-account, /my-account/orders, with or without trailing slash
            if (preg_match('#^/my-account(?:/|$)#i', $path)) {
                // Preserve query string if present
                $qs = $_SERVER['QUERY_STRING'] ?? '';
                $request_uri = $path . ($qs !== '' ? '?' . $qs : '');

                // Build a same-origin absolute URL to return to after login
                $return_to = home_url($request_uri);

                // Use wp_login_url to generate login URL carrying redirect_to, and wp_safe_redirect for security
                wp_safe_redirect(wp_login_url($return_to));
                exit;
            }
        }

        // From here on, only run the rest of the Casdoor intercepts if the plugin is active
        $activated = absint(casdoor_get_option('active'));
        if (!$activated) {
            return;
        }

        global $wp_query;
        $auth = $wp_query->get('auth');
        $options = get_option('casdoor_options');

        if ($auth !== '') {
            // casdoor will add another ? to the uri, this will make the value of auth like this : casdoor?code=c9550137370a99bc2137
            $matches = [];
            preg_match('/^([a-zA-Z]+)(\?code=[a-zA-Z0-9]+)?$/', $auth, $matches);
            if (count($matches) == 3 && $matches[1] == 'casdoor') {
                $tmp = explode('=', $matches[2]);
                if ($tmp[0] == '?code') {
                    $url =  home_url("?auth=casdoor&code={$tmp[1]}");
                    wp_redirect($url);
                    exit;
                }
            }
        }

        global $pagenow;
        $message = $wp_query->get('message');
        if ($pagenow == 'index.php' && isset($message)) {
            $options['auto_sso'] = 0;
            require_once(CASDOOR_PLUGIN_DIR . '/templates/error-msg.php');
        }

        // Auto SSO for users that are not logged in.
        $auto_sso = isset($options['auto_sso']) && $options['auto_sso'] == 1 && !is_user_logged_in();

        if ($auth == 'casdoor' || $auto_sso) {
            require_once(CASDOOR_PLUGIN_DIR . '/includes/callback.php');
            exit;
        }
    }
}

$rewrites = new Rewrites();
add_filter('rewrite_rules_array', [$rewrites, 'create_rewrite_rules']);
add_filter('query_vars', [$rewrites, 'add_query_vars']);
add_filter('wp_loaded', [$rewrites, 'flush_rewrite_rules']);
add_action('template_redirect', [$rewrites, 'template_redirect_intercept']);
