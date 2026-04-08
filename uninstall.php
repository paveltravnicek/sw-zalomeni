<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('sw_zalomeni_settings');
delete_option('sw_zalomeni_compiled_rules');

delete_option('zalomeni_version');
delete_option('zalomeni_prepositions');
delete_option('zalomeni_prepositions_list');
delete_option('zalomeni_conjunctions');
delete_option('zalomeni_conjunctions_list');
delete_option('zalomeni_abbreviations');
delete_option('zalomeni_abbreviations_list');
delete_option('zalomeni_between_number_and_unit');
delete_option('zalomeni_between_number_and_unit_list');
delete_option('zalomeni_spaces_in_scales');
delete_option('zalomeni_space_between_numbers');
delete_option('zalomeni_space_after_ordered_number');
delete_option('zalomeni_custom_terms');
delete_option('zalomeni_matches');
delete_option('zalomeni_replacements');
