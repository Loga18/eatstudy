<?php
/**
 * Plugin installer helper
 *
 * @author              Mobin Ghasempoor
 * @package             wp-parsi-date
 * @subpackage          Core/Install
 */

register_activation_hook(WP_PARSI_ROOT, 'wpp_install');

/**
 * Copys files from plugin languages folder to global languages folder
 *
 * @return              void
 * @since               1.0
 */
function wpp_install()
{
    update_option('wpp_settings', array());
}