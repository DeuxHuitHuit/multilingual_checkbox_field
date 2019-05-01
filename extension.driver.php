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
			return Symphony::Database()
				->create(self::FIELD_TABLE)
				->ifNotExists()
				->fields([
					'id' => [
						'type' => 'int(11)',
						'auto' => true,
					],
					'field_id' => 'int(11)',
					'default_state' => [
						'type' => 'enum',
						'values' => ['on', 'off'],
						'default' => 'on',
					],
					'description' => [
						'type' => 'varchar(255)',
						'null' => true,
					],
					'default_main_lang' => [
						'type' => 'enum',
						'values' => ['yes', 'no'],
						'default' => 'no',
					],
					'required_languages' => [
						'type' => 'varchar(255)',
						'null' => true,
					],
				])
				->keys([
					'id' => 'primary',
					'field_id' => 'key',
				])
				->execute()
				->success();
		}

		private function dropFieldTable() {
			return Symphony::Database()
				->drop(self::FIELD_TABLE)
				->ifExists()
				->execute()
				->success();
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
					'page'     => '/system/preferences/',
					'delegate' => 'Save',
					'callback' => 'dSave'
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
			$label->prependChild(Widget::Input('settings[multilingual_checkbox_field][consolidate]', 'yes', 'checkbox', array('checked' => 'checked')));
			$group->appendChild($label);
			$group->appendChild(new XMLElement('p', __('Check this field if you want to consolidate database by <b>keeping</b> entry values of removed/old Language Driver language codes. Entry values of current language codes will not be affected.'), array('class' => 'help')));

			$context['wrapper']->appendChild($group);
		}

		/**
		 * Edits the preferences to be saved
		 *
		 * @param array $context
		 */
		public function dSave($context) {
			// prevent the saving of the values
			unset($context['settings']['multilingual_checkbox_field']);
		}

		/**
		 * Save options from Preferences page
		 *
		 * @param array $context
		 */
		public function dFLSavePreferences($context) {
			$fields = Symphony::Database()
				->select(['field_id'])
				->from(self::FIELD_TABLE)
				->execute()
				->rows();

			if (is_array($fields) && !empty($fields)) {
				$new_languages = $context['new_langs'];

				// Foreach field check multilanguage values foreach language
				foreach ($fields as $field) {
					$entries_table = 'tbl_entries_data_'.$field["field_id"];

					try {
						$current_columns = Symphony::Database()->fetch("SHOW COLUMNS FROM `$entries_table` LIKE 'value-%';");
					} catch (DatabaseException $dbe) {
						// Field doesn't exist. Better remove it's settings
						Symphony::Database()
							->delete(self::FIELD_TABLE)
							->where(['field_id' => $field['field_id']])
							->execute()
							->success();

						continue;
					}

					$valid_columns = array();

					// Remove obsolete fields
					if ($current_columns && !empty($current_columns)) {
						$consolidate = $_POST['settings']['multilingual_checkbox_field']['consolidate'] === 'yes';

						foreach ($current_columns as $column) {
							$column_name = $column['Field'];

							$lc = str_replace('value-', '', $column_name);

							// If not consolidate option AND column lang_code not in supported languages codes -> drop Column
							if (!$consolidate && !in_array($lc, $new_languages)) {
								Symphony::Database()
									->alter($entries_table)
									->dropKey('value-' . $lc)
									->drop('value-' . $lc)
									->execute()
									->success();
							}
							else {
								$valid_columns[] = $column_name;
							}
						}
					}

					// Add new fields
					foreach ($new_languages as $lc) {
						// if columns for language don't exist, create them
						if (!in_array("value-$lc", $valid_columns)) {
							Symphony::Database()
								->alter($entries_table)
								->add([
									'value-' . $lc => [
										'type' => 'enum',
										'values' => ['yes', 'no'],
										'default' => 'no',
									],
								])
								->addKey([
									'value-' . $lc => 'key',
								])
								->execute()
								->success();
						}
					}
				}
			}
		}
	}
