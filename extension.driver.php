<?php

	if (!defined('__IN_SYMPHONY__')) {
		die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');
	}

	Class Extension_Multilingual_Checkbox_Field extends Extension {

		const FIELD_TABLE = 'tbl_fields_multilingual_checkbox';
		const PUBLISH_HEADERS = 1;
		const SETTINGS_HEADERS = 4;
		private static $appendedHeaders = 0;

		/*------------------------------------------------------------------------------------------------*/
		/*  Installation  */
		/*------------------------------------------------------------------------------------------------*/

		public function install() {
			return $this->createFieldTable();
		}

		public function update($previousVersion = false) {
			return true;
		}

		public function uninstall() {
			return $this->dropFieldTable();
		}

		private function createFieldTable() {
			return Symphony::Database()->query(sprintf("
				CREATE TABLE IF NOT EXISTS `%s` (
					`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`field_id` INT(11) UNSIGNED NOT NULL,
					`default_state` ENUM('on', 'off') DEFAULT 'on',
					`description` VARCHAR(255) DEFAULT NULL,
					`default_main_lang` ENUM('yes', 'no') DEFAULT 'no',
					`required_languages` VARCHAR(255) DEFAULT NULL,
					PRIMARY KEY (`id`),
					KEY `field_id` (`field_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;",
				self::FIELD_TABLE
			));
		}

		private function dropFieldTable() {
			return Symphony::Database()->query(sprintf(
				"DROP TABLE IF EXISTS `%s`",
				self::FIELD_TABLE
			));
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Public utilities  */
		/*------------------------------------------------------------------------------------------------*/

		/**
		 * Add headers to the page.
		 *
		 * @param $type
		 */
		static public function appendHeaders($type) {
			if (
				(self::$appendedHeaders & $type) !== $type
				&& class_exists('Administration')
				&& Administration::instance() instanceof Administration
				&& Administration::instance()->Page instanceof HTMLPage
			) {
				$page = Administration::instance()->Page;

				if ($type === self::PUBLISH_HEADERS) {
					$page->addStylesheetToHead(URL . '/extensions/multilingual_checkbox_field/assets/multilingual_checkbox_field.publish.css', 'screen');
				}

				if ($type === self::SETTINGS_HEADERS) {
					$page->addScriptToHead(URL . '/extensions/multilingual_checkbox_field/assets/multilingual_checkbox_field.settings.js');
				}

				self::$appendedHeaders &= $type;
			}
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Delegates  */
		/*------------------------------------------------------------------------------------------------*/

		public function getSubscribedDelegates() {
			return array(
				array(
					'page'     => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'dAddCustomPreferenceFieldsets'
				),
				array(
					'page'     => '/extensions/frontend_localisation/',
					'delegate' => 'FLSavePreferences',
					'callback' => 'dFLSavePreferences'
				),
			);
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  System preferences  */
		/*------------------------------------------------------------------------------------------------*/

		/**
		 * Display options on Preferences page.
		 *
		 * @param array $context
		 */
		public function dAddCustomPreferenceFieldsets($context) {
			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(new XMLElement('legend', __('Multilingual Check Box')));

			$label = Widget::Label(__('Consolidate entry data'));
			$label->appendChild(Widget::Input('settings[multilingual_checkbox_field][consolidate]', 'yes', 'checkbox', array('checked' => 'checked')));
			$group->appendChild($label);
			$group->appendChild(new XMLElement('p', __('Check this field if you want to consolidate database by <b>keeping</b> entry values of removed/old Language Driver language codes. Entry values of current language codes will not be affected.'), array('class' => 'help')));

			$context['wrapper']->appendChild($group);
		}

		/**
		 * Save options from Preferences page
		 *
		 * @param array $context
		 */
		public function dFLSavePreferences($context) {
			if ($fields = Symphony::Database()->fetch(sprintf("SELECT `field_id` FROM `%s`", self::FIELD_TABLE))) {
				$new_languages = $context['new_langs'];

				// Foreach field check multilanguage values foreach language
				foreach ($fields as $field) {
					$entries_table = "tbl_entries_data_{$field["field_id"]}";

					try {
						$current_columns = Symphony::Database()->fetch("SHOW COLUMNS FROM `$entries_table` LIKE 'handle-%';");
					} catch (DatabaseException $dbe) {
						// Field doesn't exist. Better remove it's settings
						Symphony::Database()->query(sprintf(
								"DELETE FROM `%s` WHERE `field_id` = %s;",
								self::FIELD_TABLE, $field["field_id"])
						);
						continue;
					}

					$valid_columns = array();

					// Remove obsolete fields
					if ($current_columns) {
						$consolidate = $_POST['settings']['multilingual_checkbox_field']['consolidate'] === 'yes';

						foreach ($current_columns as $column) {
							$column_name = $column['Field'];

							$lc = str_replace('handle-', '', $column_name);

							// If not consolidate option AND column lang_code not in supported languages codes -> drop Column
							if (!$consolidate && !in_array($lc, $new_languages)) {
								Symphony::Database()->query(
									"ALTER TABLE `$entries_table`
										DROP COLUMN `handle-$lc`,
										DROP COLUMN `value-$lc`,
										DROP COLUMN `value_formatted-$lc`,
										DROP COLUMN `word_count-$lc`;"
								);
							}
							else {
								$valid_columns[] = $column_name;
							}
						}
					}

					// Add new fields
					foreach ($new_languages as $lc) {
						// if columns for language don't exist, create them

						if (!in_array("handle-$lc", $valid_columns)) {
							Symphony::Database()->query(
								"ALTER TABLE `$entries_table`
									ADD COLUMN `handle-$lc` varchar(255) default NULL,
									ADD COLUMN `value-$lc` text default NULL,
									ADD COLUMN `value_formatted-$lc` text default NULL,
									ADD COLUMN `word_count-$lc` int(11) default NULL;"
							);
						}
					}
				}
			}
		}
	}
