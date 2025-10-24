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

    /**
     * Whether the WooCommerce edit-account redirect feature is enabled.
     */
    public function wc_feature_enabled(): bool
    {
        return function_exists('casdoor_get_option') && absint(casdoor_get_option('woo_edit_account_redirect')) === 1;
    }

    /**
     * Get Casdoor Backend base URL from the Casdoor plugin settings.
     *
     * @return string e.g., https://sso.example.com (no trailing slash) or empty string if not set
     */
    public function wc_get_casdoor_base(): string
    {
        if (!function_exists('casdoor_get_option')) {
            return '';
        }
        $backend = (string) casdoor_get_option('backend');
        $backend = trim($backend);
        return $backend === '' ? '' : rtrim($backend, '/');
    }

    /**
     * Inject inline JS in footer to rewrite links and add target="_blank".
     */
    public function wc_footer_script()
    {
        if (!$this->wc_feature_enabled()) {
            return;
        }

        $casdoor_base = $this->wc_get_casdoor_base();

        // Pass data into JS safely
        $data = [
            'base'   => $casdoor_base,
            'origin' => home_url('/'),
        ];
        ?>
        <script>
        (function() {
          var DATA = <?php echo wp_json_encode($data, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
          var CASDOOR_BASE = (DATA.base || '').replace(/\/+$/, ''); // no trailing slash
          var SITE_ORIGIN;
          try {
            SITE_ORIGIN = new URL(DATA.origin).origin;
          } catch (e) {
            SITE_ORIGIN = window.location.origin;
          }

          var pathRules = [
            { re: /^\/my-account\/edit-account\/?$/i, casdoorPath: '/account' }
          ];

          function normalizeUrl(href) {
            try { return new URL(href, window.location.origin); }
            catch (e) { return null; }
          }

          function maybeRewriteAndTarget(a) {
            if (!a || !a.getAttribute) return;

            var originalHref = a.getAttribute('href');
            if (!originalHref) return;

            var url = normalizeUrl(originalHref);
            if (!url) return;

            // Only consider links pointing to this site
            if (url.origin !== SITE_ORIGIN) return;

            // Normalize trailing slash style to match rules
            var path = url.pathname;
            if (!/\/$/.test(path)) {
              // Keep both variants by testing both; normalize to have trailing slash for comparison
              path = path + '/';
            }

            for (var i = 0; i < pathRules.length; i++) {
              var rule = pathRules[i];
              if (rule.re.test(path)) {
                // Always open in new tab
                a.setAttribute('target', '_blank');

                // Security best practice with _blank
                var rel = (a.getAttribute('rel') || '');
                var parts = rel.toLowerCase().split(/\s+/).filter(Boolean);
                var set = {};
                parts.forEach(function(p){ set[p] = true; });
                set['noopener'] = true;
                set['noreferrer'] = true;
                a.setAttribute('rel', Object.keys(set).join(' '));

                // If Casdoor base configured, rewrite href
                if (CASDOOR_BASE) {
                  var newHref = CASDOOR_BASE + rule.casdoorPath;
                  if (a.href !== newHref) {
                    a.setAttribute('href', newHref);
                  }
                }
                return;
              }
            }
          }

          function processAll(root) {
            var scope = (root && root.querySelectorAll) ? root : document;
            var links = scope.querySelectorAll('a[href]');
            for (var i = 0; i < links.length; i++) {
              maybeRewriteAndTarget(links[i]);
            }
          }

          // Initial run
          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { processAll(document); });
          } else {
            processAll(document);
          }

          // Observe for dynamically injected links (menus, AJAX content, etc.)
          try {
            var mo = new MutationObserver(function(mutations) {
              for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type === 'childList') {
                  for (var j = 0; j < m.addedNodes.length; j++) {
                    var node = m.addedNodes[j];
                    if (node && node.nodeType === 1) {
                      if (node.tagName === 'A') {
                        maybeRewriteAndTarget(node);
                      } else if (node.querySelectorAll) {
                        processAll(node);
                      }
                    }
                  }
                } else if (m.type === 'attributes' && m.target && m.target.tagName === 'A' && m.attributeName === 'href') {
                  maybeRewriteAndTarget(m.target);
                }
              }
            });
            mo.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
          } catch (e) {
            // MutationObserver not available or failed; initial processing still applied
          }
        })();
        </script>
        <?php
    }

    /**
     * Permit safe redirects to the SSO host (for any code that uses wp_safe_redirect).
     */
    public function allowed_redirect_hosts($hosts)
    {
        if (!$this->wc_feature_enabled()) {
            return $hosts;
        }
        $casdoor = $this->wc_get_casdoor_base();
        if ($casdoor !== '') {
            $host = parse_url($casdoor, PHP_URL_HOST);
            if ($host && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }
        return $hosts;
    }

    public function template_redirect_intercept(): void
    {
        $activated = absint(casdoor_get_option('active'));
        if (!$activated) {
            return;
        }

        // WooCommerce direct visit redirect fallback
        if ($this->wc_feature_enabled()) {
            $casdoor = $this->wc_get_casdoor_base();
            if ($casdoor !== '') {
                $req_uri = $_SERVER['REQUEST_URI'] ?? '';
                $path    = parse_url($req_uri, PHP_URL_PATH) ?: '/';
                $path    = '/' . ltrim($path, '/');

                if (preg_match('#^/my-account/edit-account/?$#i', $path)) {
                    wp_redirect($casdoor . '/account', 301);
                    exit;
                }
            }
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

// Hook footer JS and safe-redirect host whitelist without adding a new file
add_action('wp_footer', [$rewrites, 'wc_footer_script'], 99);
add_filter('allowed_redirect_hosts', [$rewrites, 'allowed_redirect_hosts']);
