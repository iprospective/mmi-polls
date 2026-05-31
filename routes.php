<?php
// Table de routes : [METHOD, regex, controller_file (sans extension), handler_function]
// Le dispatcher dans index.php charge le fichier controller, puis appelle la fonction
// avec les groupes captures par le regex en arguments.
return [
    ['GET',  '#^/$#',                                                     'home',                'route_home'],

    ['GET',  '#^/admin/login$#',                                          'admin_auth',          'route_admin_login_form'],
    ['POST', '#^/admin/login$#',                                          'admin_auth',          'route_admin_login'],
    ['POST', '#^/admin/logout$#',                                         'admin_auth',          'route_admin_logout'],

    // Manager (inscription, login mot de passe + magic-link, dashboard)
    ['GET',  '#^/register$#',                                             'manager_auth',        'route_register_form'],
    ['POST', '#^/register$#',                                             'manager_auth',        'route_register'],
    ['GET',  '#^/login$#',                                                'manager_auth',        'route_login_form'],
    ['POST', '#^/login$#',                                                'manager_auth',        'route_login'],
    ['POST', '#^/login/magic$#',                                          'manager_auth',        'route_login_magic'],
    ['GET',  '#^/auth$#',                                                 'manager_auth',        'route_consume_magic'],
    ['POST', '#^/logout$#',                                               'manager_auth',        'route_logout'],
    ['GET',  '#^/manager$#',                                              'manager_dashboard',   'route_manager_dashboard'],

    // Admin : gestion des comptes manager
    ['GET',  '#^/admin/managers$#',                                       'admin_managers',      'route_admin_managers'],
    ['POST', '#^/admin/managers/(\d+)/validate$#',                        'admin_managers',      'route_admin_validate_manager'],
    ['POST', '#^/admin/managers/(\d+)/reject$#',                          'admin_managers',      'route_admin_reject_manager'],
    ['POST', '#^/admin/managers/(\d+)/delete$#',                          'admin_managers',      'route_admin_delete_manager'],

    ['GET',  '#^/admin$#',                                                'admin_polls',         'route_admin_list'],
    ['POST', '#^/admin/polls$#',                                          'admin_polls',         'route_admin_create_poll'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)$#',                             'admin_polls',         'route_admin_poll'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)$#',                             'admin_polls',         'route_admin_update_poll'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/delete$#',                      'admin_polls',         'route_admin_delete_poll'],

    ['GET',  '#^/admin/polls/([0-9a-f-]+)/dates$#',                       'admin_dates',         'route_admin_dates'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/dates$#',                       'admin_dates',         'route_admin_add_date'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/dates/bulk$#',                  'admin_dates',         'route_admin_add_dates_bulk'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/dates/(\d+)/delete$#',          'admin_dates',         'route_admin_delete_date'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/dates/(\d+)/choices$#',         'admin_dates',         'route_admin_add_choice'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/choices/(\d+)/delete$#',        'admin_dates',         'route_admin_delete_choice'],

    ['GET',  '#^/admin/polls/([0-9a-f-]+)/participants$#',                'admin_participants',  'route_admin_participants_list'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/participants$#',                'admin_participants',  'route_admin_create_participant'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)$#',          'admin_participants',  'route_admin_edit_participant'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)$#',          'admin_participants',  'route_admin_update_participant'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)/delete$#',   'admin_participants',  'route_admin_delete_participant'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)/toggle-visibility$#', 'admin_participants', 'route_admin_toggle_participant_visibility'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)/remind$#',    'admin_participants',  'route_admin_remind_participant'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/participants/remind-all$#',      'admin_participants',  'route_admin_remind_non_responders'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)/calendar$#', 'admin_participants',  'route_admin_participant_calendar'],

    ['GET',  '#^/admin/polls/([0-9a-f-]+)/assignments$#',                 'admin_assignments',   'route_admin_assignments'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/assignments$#',                 'admin_assignments',   'route_admin_save_assignments'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/assignments/auto-fill$#',       'admin_assignments',   'route_admin_auto_fill_assignments'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/assignments/clear$#',           'admin_assignments',   'route_admin_clear_assignments'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/calendar$#',                    'admin_calendar',      'route_admin_calendar'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/activity$#',                    'admin_activity',      'route_admin_activity'],

    ['POST', '#^/admin/polls/([0-9a-f-]+)/contact-email$#',               'admin_notifications', 'route_admin_set_contact_email'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/assignments/notify$#',          'admin_notifications', 'route_admin_send_notifications'],

    ['GET',  '#^/admin/polls/([0-9a-f-]+)/settings$#',                    'admin_settings',      'route_admin_settings'],

    ['POST', '#^/admin/polls/([0-9a-f-]+)/managers$#',                    'poll_managers',       'route_poll_add_manager'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/managers/(\d+)/delete$#',       'poll_managers',       'route_poll_remove_manager'],

    ['GET',  '#^/p/([0-9a-f-]+)$#',                                       'poll_public',         'route_poll_show'],
    ['GET',  '#^/p/([0-9a-f-]+)/login$#',                                 'poll_public',         'route_poll_login_form'],
    ['POST', '#^/p/([0-9a-f-]+)/login$#',                                 'poll_public',         'route_poll_send_link'],
    ['GET',  '#^/p/([0-9a-f-]+)/auth$#',                                  'poll_public',         'route_poll_consume_link'],
    ['POST', '#^/p/([0-9a-f-]+)/logout$#',                                'poll_public',         'route_poll_logout'],

    ['GET',  '#^/p/([0-9a-f-]+)/me$#',                                    'poll_votes',          'route_poll_me'],
    ['POST', '#^/p/([0-9a-f-]+)/me$#',                                    'poll_votes',          'route_poll_save_votes'],
    ['POST', '#^/p/([0-9a-f-]+)/me/delete$#',                             'poll_votes',          'route_poll_delete_votes'],

    ['GET',  '#^/p/([0-9a-f-]+)/confirm$#',                               'poll_confirm',        'route_poll_confirm_get'],
    ['POST', '#^/p/([0-9a-f-]+)/confirm$#',                               'poll_confirm',        'route_poll_confirm_post'],

    // Flux iCal — accessible par token (pas de session)
    ['GET',  '#^/ical/([a-f0-9]+)\.ics$#',                                'ical',                'route_ical_feed'],
];
