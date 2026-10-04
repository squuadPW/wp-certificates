# Renombrado del módulo de certificación (ADR 0004, paso 4b-1)

Tabla de referencia del renombrado mecánico de `certification/` (copia literal del módulo de EduSystem, paso 4a)
a los nombres de wp-certificates, según la sección 2.1 del ADR 0004. Solo cambian nombres: ninguna línea de lógica.

## Reglas

- Funciones, hooks, opciones, transitorios, metadatos, nonces, acciones `admin_post_*`, capacidades y rol:
  `edusystem_X` → `squuad_cert_X`.
- Las 7 funciones sin prefijo → `squuad_cert_<nombre>`. Los nombres de sus acciones AJAX
  (`wp_ajax_create_enrollment_document`, `wp_ajax_load_signatures_data`) siguen igual de momento: los usa el JS.
- Constantes `EDUSYSTEM_X` → `SQUUAD_CERT_X` (excepciones en la tabla).
- Tablas, pantallas, parámetro de invitación, bloqueos, prefijo HMAC y comando WP-CLI: según el ADR (tabla).

## Sin renombrar todavía

| Nombre | Por qué |
|---|---|
| `edusystem_set_log`, `edusystem_certification_outbox`, `edusystem_db_version`, `EDUSYSTEM_PATH`/`_URL`/`_VERSION` | Son de EduSystem; se quitan en 4b-2 (contrato, log propio) y 4b-4 (rutas en `bootstrap.php`, logo de `create-enrollment.php`) |
| nonce `edusystem_signatures`, clave `edusystem_document_fields_`, acciones AJAX, ids y clases HTML `edusystem-*`/`edusig-*`, `edusystemSignatures`, `signature-pad-edusystem.js` | Parte JS/front: el nonce lo crea EduSystem (`public/functions/setup.php`) y el CSS de EduSystem usa los ids; se cambian juntos más adelante |
| `edusystem-template`, `EDUSIG1`/`EDUSIG2` | Van dentro de datos sellados (ADR 0004, 2.2) |
| `edusystem_signature_legacy_max_id` → renombrada | Desaparece en 4b-3 (esquema nacido completo) |
| dominio de traducción `edusystem` | Pasa a `wp-certificates` con sus `.po` (paso 8) |

## Tabla completa

### Funciones definidas por el módulo

| Antes | Ahora |
|---|---|
| `create_enrollment_document_callback` | `squuad_cert_create_enrollment_document_callback` |
| `edusystem_book_entries_enabled` | `squuad_cert_book_entries_enabled` |
| `edusystem_book_entry_get` | `squuad_cert_book_entry_get` |
| `edusystem_book_entry_insert` | `squuad_cert_book_entry_insert` |
| `edusystem_book_entry_mark_void` | `squuad_cert_book_entry_mark_void` |
| `edusystem_book_entry_pending_decision` | `squuad_cert_book_entry_pending_decision` |
| `edusystem_convert_document_grade` | `squuad_cert_convert_document_grade` |
| `edusystem_convert_document_php` | `squuad_cert_convert_document_php` |
| `edusystem_document_condition_met` | `squuad_cert_document_condition_met` |
| `edusystem_document_conditions` | `squuad_cert_document_conditions` |
| `edusystem_document_field_has_options` | `squuad_cert_document_field_has_options` |
| `edusystem_document_field_key_is_valid` | `squuad_cert_document_field_key_is_valid` |
| `edusystem_document_field_text` | `squuad_cert_document_field_text` |
| `edusystem_document_field_types` | `squuad_cert_document_field_types` |
| `edusystem_document_fields_empty_replacements` | `squuad_cert_document_fields_empty_replacements` |
| `edusystem_document_fields_replacements` | `squuad_cert_document_fields_replacements` |
| `edusystem_document_fields_reserved_keys` | `squuad_cert_document_fields_reserved_keys` |
| `edusystem_document_fields_stored_values` | `squuad_cert_document_fields_stored_values` |
| `edusystem_document_fields_values` | `squuad_cert_document_fields_values` |
| `edusystem_document_issue_can` | `squuad_cert_document_issue_can` |
| `edusystem_document_issue_handle` | `squuad_cert_document_issue_handle` |
| `edusystem_document_issue_reserve_book_line` | `squuad_cert_document_issue_reserve_book_line` |
| `edusystem_document_issue_resolve_book` | `squuad_cert_document_issue_resolve_book` |
| `edusystem_document_issue_void_book_line` | `squuad_cert_document_issue_void_book_line` |
| `edusystem_document_issued_book_decision_handle` | `squuad_cert_document_issued_book_decision_handle` |
| `edusystem_document_issued_decline_handle` | `squuad_cert_document_issued_decline_handle` |
| `edusystem_document_issued_section` | `squuad_cert_document_issued_section` |
| `edusystem_document_preview_data` | `squuad_cert_document_preview_data` |
| `edusystem_document_preview_markup` | `squuad_cert_document_preview_markup` |
| `edusystem_document_preview_mode` | `squuad_cert_document_preview_mode` |
| `edusystem_document_preview_notes_table` | `squuad_cert_document_preview_notes_table` |
| `edusystem_document_preview_plain_table` | `squuad_cert_document_preview_plain_table` |
| `edusystem_document_preview_replacements` | `squuad_cert_document_preview_replacements` |
| `edusystem_document_preview_signature_block` | `squuad_cert_document_preview_signature_block` |
| `edusystem_document_preview_signature_box` | `squuad_cert_document_preview_signature_box` |
| `edusystem_document_preview_signers` | `squuad_cert_document_preview_signers` |
| `edusystem_document_request_condition` | `squuad_cert_document_request_condition` |
| `edusystem_document_request_condition_set` | `squuad_cert_document_request_condition_set` |
| `edusystem_document_signing_current_document` | `squuad_cert_document_signing_current_document` |
| `edusystem_document_signing_handle_save` | `squuad_cert_document_signing_handle_save` |
| `edusystem_document_signing_panel` | `squuad_cert_document_signing_panel` |
| `edusystem_get_automatic_document_by_identificator` | `squuad_cert_get_automatic_document_by_identificator` |
| `edusystem_get_document_fields` | `squuad_cert_get_document_fields` |
| `edusystem_last_completed_grade_field` | `squuad_cert_last_completed_grade_field` |
| `edusystem_legacy_institutional_signature` | `squuad_cert_legacy_institutional_signature` |
| `edusystem_legacy_signature_affected_documents` | `squuad_cert_legacy_signature_affected_documents` |
| `edusystem_legacy_signatures_admin_notice` | `squuad_cert_legacy_signatures_admin_notice` |
| `edusystem_legacy_signatures_dismiss_handle` | `squuad_cert_legacy_signatures_dismiss_handle` |
| `edusystem_legacy_signatures_migration_notify` | `squuad_cert_legacy_signatures_migration_notify` |
| `edusystem_migrate_documents_php` | `squuad_cert_migrate_documents_php` |
| `edusystem_missing_documents_list_html` | `squuad_cert_missing_documents_list_html` |
| `edusystem_missing_documents_pending` | `squuad_cert_missing_documents_pending` |
| `edusystem_missing_letter_conversion_available` | `squuad_cert_missing_letter_conversion_available` |
| `edusystem_missing_letter_conversion_notice` | `squuad_cert_missing_letter_conversion_notice` |
| `edusystem_missing_letter_convert_handle` | `squuad_cert_missing_letter_convert_handle` |
| `edusystem_missing_letter_is_automatic` | `squuad_cert_missing_letter_is_automatic` |
| `edusystem_missing_letter_template` | `squuad_cert_missing_letter_template` |
| `edusystem_my_signature_page` | `squuad_cert_my_signature_page` |
| `edusystem_notice_documents_php_pending` | `squuad_cert_notice_documents_php_pending` |
| `edusystem_render_document_fields` | `squuad_cert_render_document_fields` |
| `edusystem_request_signers` | `squuad_cert_request_signers` |
| `edusystem_request_signers_fix` | `squuad_cert_request_signers_fix` |
| `edusystem_revoke_signatures` | `squuad_cert_revoke_signatures` |
| `edusystem_sanitize_document_fields` | `squuad_cert_sanitize_document_fields` |
| `edusystem_schema_signers` | `squuad_cert_schema_signers` |
| `edusystem_signature_account_documents_to_sign` | `squuad_cert_signature_account_documents_to_sign` |
| `edusystem_signature_account_pdf_requests` | `squuad_cert_signature_account_pdf_requests` |
| `edusystem_signature_add_key` | `squuad_cert_signature_add_key` |
| `edusystem_signature_append_institutional_block` | `squuad_cert_signature_append_institutional_block` |
| `edusystem_signature_batch_account_url` | `squuad_cert_signature_batch_account_url` |
| `edusystem_signature_batch_confirm` | `squuad_cert_signature_batch_confirm` |
| `edusystem_signature_batch_consent_text` | `squuad_cert_signature_batch_consent_text` |
| `edusystem_signature_batch_get` | `squuad_cert_signature_batch_get` |
| `edusystem_signature_batch_holder_candidates` | `squuad_cert_signature_batch_holder_candidates` |
| `edusystem_signature_batch_notice` | `squuad_cert_signature_batch_notice` |
| `edusystem_signature_batch_prepare` | `squuad_cert_signature_batch_prepare` |
| `edusystem_signature_batch_print_notice` | `squuad_cert_signature_batch_print_notice` |
| `edusystem_signature_batch_take_notice` | `squuad_cert_signature_batch_take_notice` |
| `edusystem_signature_canonical` | `squuad_cert_signature_canonical` |
| `edusystem_signature_canonical_for` | `squuad_cert_signature_canonical_for` |
| `edusystem_signature_canonical_v2` | `squuad_cert_signature_canonical_v2` |
| `edusystem_signature_chain_fingerprint_at` | `squuad_cert_signature_chain_fingerprint_at` |
| `edusystem_signature_chain_set_head` | `squuad_cert_signature_chain_set_head` |
| `edusystem_signature_cli_verify` | `squuad_cert_signature_cli_verify` |
| `edusystem_signature_consent_evidence` | `squuad_cert_signature_consent_evidence` |
| `edusystem_signature_consent_text` | `squuad_cert_signature_consent_text` |
| `edusystem_signature_context_labels` | `squuad_cert_signature_context_labels` |
| `edusystem_signature_current_key` | `squuad_cert_signature_current_key` |
| `edusystem_signature_doc_version_hash` | `squuad_cert_signature_doc_version_hash` |
| `edusystem_signature_document_is_valid` | `squuad_cert_signature_document_is_valid` |
| `edusystem_signature_ensure_key` | `squuad_cert_signature_ensure_key` |
| `edusystem_signature_escape_replacements` | `squuad_cert_signature_escape_replacements` |
| `edusystem_signature_evidence_enabled` | `squuad_cert_signature_evidence_enabled` |
| `edusystem_signature_from_request` | `squuad_cert_signature_from_request` |
| `edusystem_signature_handle_holder_batch_confirm` | `squuad_cert_signature_handle_holder_batch_confirm` |
| `edusystem_signature_handle_holder_batch_prepare` | `squuad_cert_signature_handle_holder_batch_prepare` |
| `edusystem_signature_handle_request_submission` | `squuad_cert_signature_handle_request_submission` |
| `edusystem_signature_has_legacy_partial` | `squuad_cert_signature_has_legacy_partial` |
| `edusystem_signature_inline_images` | `squuad_cert_signature_inline_images` |
| `edusystem_signature_insert` | `squuad_cert_signature_insert` |
| `edusystem_signature_institutional_replacements` | `squuad_cert_signature_institutional_replacements` |
| `edusystem_signature_integrity_check_request` | `squuad_cert_signature_integrity_check_request` |
| `edusystem_signature_integrity_grant_cap` | `squuad_cert_signature_integrity_grant_cap` |
| `edusystem_signature_integrity_handle_csv` | `squuad_cert_signature_integrity_handle_csv` |
| `edusystem_signature_integrity_handle_rotate` | `squuad_cert_signature_integrity_handle_rotate` |
| `edusystem_signature_integrity_handle_verify` | `squuad_cert_signature_integrity_handle_verify` |
| `edusystem_signature_integrity_install` | `squuad_cert_signature_integrity_install` |
| `edusystem_signature_integrity_label` | `squuad_cert_signature_integrity_label` |
| `edusystem_signature_integrity_menu` | `squuad_cert_signature_integrity_menu` |
| `edusystem_signature_integrity_page` | `squuad_cert_signature_integrity_page` |
| `edusystem_signature_integrity_redirect` | `squuad_cert_signature_integrity_redirect` |
| `edusystem_signature_issue_document` | `squuad_cert_signature_issue_document` |
| `edusystem_signature_issue_signers` | `squuad_cert_signature_issue_signers` |
| `edusystem_signature_issued_decline` | `squuad_cert_signature_issued_decline` |
| `edusystem_signature_issued_for_student` | `squuad_cert_signature_issued_for_student` |
| `edusystem_signature_issued_latest` | `squuad_cert_signature_issued_latest` |
| `edusystem_signature_issued_options` | `squuad_cert_signature_issued_options` |
| `edusystem_signature_key_by_id` | `squuad_cert_signature_key_by_id` |
| `edusystem_signature_key_rank` | `squuad_cert_signature_key_rank` |
| `edusystem_signature_key_retired_at` | `squuad_cert_signature_key_retired_at` |
| `edusystem_signature_legacy_rows` | `squuad_cert_signature_legacy_rows` |
| `edusystem_signature_lock_name` | `squuad_cert_signature_lock_name` |
| `edusystem_signature_mark_switched_session` | `squuad_cert_signature_mark_switched_session` |
| `edusystem_signature_order_close_by_upload` | `squuad_cert_signature_order_close_by_upload` |
| `edusystem_signature_order_decline` | `squuad_cert_signature_order_decline` |
| `edusystem_signature_order_has_open_request` | `squuad_cert_signature_order_has_open_request` |
| `edusystem_signature_pad_box` | `squuad_cert_signature_pad_box` |
| `edusystem_signature_pdf_page` | `squuad_cert_signature_pdf_page` |
| `edusystem_signature_pdf_page_from_options` | `squuad_cert_signature_pdf_page_from_options` |
| `edusystem_signature_pending_for_user` | `squuad_cert_signature_pending_for_user` |
| `edusystem_signature_render_content` | `squuad_cert_signature_render_content` |
| `edusystem_signature_render_slot_box` | `squuad_cert_signature_render_slot_box` |
| `edusystem_signature_request_close_by_upload` | `squuad_cert_signature_request_close_by_upload` |
| `edusystem_signature_request_content` | `squuad_cert_signature_request_content` |
| `edusystem_signature_request_created_fingerprint` | `squuad_cert_signature_request_created_fingerprint` |
| `edusystem_signature_request_event_canonical` | `squuad_cert_signature_request_event_canonical` |
| `edusystem_signature_request_freeze` | `squuad_cert_signature_request_freeze` |
| `edusystem_signature_request_freeze_unlocked` | `squuad_cert_signature_request_freeze_unlocked` |
| `edusystem_signature_request_get` | `squuad_cert_signature_request_get` |
| `edusystem_signature_request_get_or_create` | `squuad_cert_signature_request_get_or_create` |
| `edusystem_signature_request_institutional_pending` | `squuad_cert_signature_request_institutional_pending` |
| `edusystem_signature_request_latest` | `squuad_cert_signature_request_latest` |
| `edusystem_signature_request_locked` | `squuad_cert_signature_request_locked` |
| `edusystem_signature_request_log_event` | `squuad_cert_signature_request_log_event` |
| `edusystem_signature_request_log_event_once` | `squuad_cert_signature_request_log_event_once` |
| `edusystem_signature_request_open_for_row` | `squuad_cert_signature_request_open_for_row` |
| `edusystem_signature_request_render_final` | `squuad_cert_signature_request_render_final` |
| `edusystem_signature_request_required_roles` | `squuad_cert_signature_request_required_roles` |
| `edusystem_signature_request_role` | `squuad_cert_signature_request_role` |
| `edusystem_signature_request_save_draft` | `squuad_cert_signature_request_save_draft` |
| `edusystem_signature_request_save_draft_unlocked` | `squuad_cert_signature_request_save_draft_unlocked` |
| `edusystem_signature_request_signed_roles` | `squuad_cert_signature_request_signed_roles` |
| `edusystem_signature_request_slot_open` | `squuad_cert_signature_request_slot_open` |
| `edusystem_signature_request_transition` | `squuad_cert_signature_request_transition` |
| `edusystem_signature_request_verify_event` | `squuad_cert_signature_request_verify_event` |
| `edusystem_signature_requests_enabled` | `squuad_cert_signature_requests_enabled` |
| `edusystem_signature_session_switched_from` | `squuad_cert_signature_session_switched_from` |
| `edusystem_signature_sign_as_holder_with` | `squuad_cert_signature_sign_as_holder_with` |
| `edusystem_signature_sign_as_signer` | `squuad_cert_signature_sign_as_signer` |
| `edusystem_signature_sign_as_signer_with` | `squuad_cert_signature_sign_as_signer_with` |
| `edusystem_signature_signer_replacements` | `squuad_cert_signature_signer_replacements` |
| `edusystem_signature_stored_keys` | `squuad_cert_signature_stored_keys` |
| `edusystem_signature_strip_unused_signer_tags` | `squuad_cert_signature_strip_unused_signer_tags` |
| `edusystem_signature_student` | `squuad_cert_signature_student` |
| `edusystem_signature_student_document_id` | `squuad_cert_signature_student_document_id` |
| `edusystem_signature_svg` | `squuad_cert_signature_svg` |
| `edusystem_signature_user_documents` | `squuad_cert_signature_user_documents` |
| `edusystem_signature_verify_chain` | `squuad_cert_signature_verify_chain` |
| `edusystem_signature_verify_row` | `squuad_cert_signature_verify_row` |
| `edusystem_signatures_store_document_fields` | `squuad_cert_signatures_store_document_fields` |
| `edusystem_signer_by_user` | `squuad_cert_signer_by_user` |
| `edusystem_signer_decline_request` | `squuad_cert_signer_decline_request` |
| `edusystem_signer_get` | `squuad_cert_signer_get` |
| `edusystem_signer_inbox` | `squuad_cert_signer_inbox` |
| `edusystem_signer_inbox_enabled` | `squuad_cert_signer_inbox_enabled` |
| `edusystem_signer_inbox_handle_batch_confirm` | `squuad_cert_signer_inbox_handle_batch_confirm` |
| `edusystem_signer_inbox_handle_batch_prepare` | `squuad_cert_signer_inbox_handle_batch_prepare` |
| `edusystem_signer_inbox_handle_decline` | `squuad_cert_signer_inbox_handle_decline` |
| `edusystem_signer_inbox_handle_sign` | `squuad_cert_signer_inbox_handle_sign` |
| `edusystem_signer_inbox_menu` | `squuad_cert_signer_inbox_menu` |
| `edusystem_signer_inbox_page` | `squuad_cert_signer_inbox_page` |
| `edusystem_signer_inbox_pending_notice` | `squuad_cert_signer_inbox_pending_notice` |
| `edusystem_signer_invitation_by_token` | `squuad_cert_signer_invitation_by_token` |
| `edusystem_signer_invite` | `squuad_cert_signer_invite` |
| `edusystem_signer_invite_new_account` | `squuad_cert_signer_invite_new_account` |
| `edusystem_signer_normalize_strokes` | `squuad_cert_signer_normalize_strokes` |
| `edusystem_signer_notify_open_slots` | `squuad_cert_signer_notify_open_slots` |
| `edusystem_signer_pdf_queue` | `squuad_cert_signer_pdf_queue` |
| `edusystem_signer_pending_invitation` | `squuad_cert_signer_pending_invitation` |
| `edusystem_signer_profile_consent_text` | `squuad_cert_signer_profile_consent_text` |
| `edusystem_signer_registration_handle` | `squuad_cert_signer_registration_handle` |
| `edusystem_signer_registration_render` | `squuad_cert_signer_registration_render` |
| `edusystem_signer_send_invitation_email` | `squuad_cert_signer_send_invitation_email` |
| `edusystem_signer_slot_key` | `squuad_cert_signer_slot_key` |
| `edusystem_signer_slot_marker` | `squuad_cert_signer_slot_marker` |
| `edusystem_signer_token_hmac` | `squuad_cert_signer_token_hmac` |
| `edusystem_signer_user_is_eligible` | `squuad_cert_signer_user_is_eligible` |
| `edusystem_signers_allow_admin_access` | `squuad_cert_signers_allow_admin_access` |
| `edusystem_signers_assets` | `squuad_cert_signers_assets` |
| `edusystem_signers_back` | `squuad_cert_signers_back` |
| `edusystem_signers_check_manage` | `squuad_cert_signers_check_manage` |
| `edusystem_signers_enabled` | `squuad_cert_signers_enabled` |
| `edusystem_signers_handle_invite` | `squuad_cert_signers_handle_invite` |
| `edusystem_signers_handle_invite_new` | `squuad_cert_signers_handle_invite_new` |
| `edusystem_signers_handle_resend` | `squuad_cert_signers_handle_resend` |
| `edusystem_signers_handle_revoke` | `squuad_cert_signers_handle_revoke` |
| `edusystem_signers_handle_save_my_signature` | `squuad_cert_signers_handle_save_my_signature` |
| `edusystem_signers_handle_status` | `squuad_cert_signers_handle_status` |
| `edusystem_signers_menu` | `squuad_cert_signers_menu` |
| `edusystem_signers_notice` | `squuad_cert_signers_notice` |
| `edusystem_signers_page` | `squuad_cert_signers_page` |
| `edusystem_signers_register_roles` | `squuad_cert_signers_register_roles` |
| `edusystem_signers_take_notice` | `squuad_cert_signers_take_notice` |
| `edusystem_signing_policies_grant_cap` | `squuad_cert_signing_policies_grant_cap` |
| `edusystem_signing_policy` | `squuad_cert_signing_policy` |
| `edusystem_signing_policy_has` | `squuad_cert_signing_policy_has` |
| `edusystem_signing_policy_hash` | `squuad_cert_signing_policy_hash` |
| `edusystem_signing_policy_save` | `squuad_cert_signing_policy_save` |
| `edusystem_third_party_signatures_blocked` | `squuad_cert_third_party_signatures_blocked` |
| `edusystem_user_signature_active` | `squuad_cert_user_signature_active` |
| `edusystem_user_signature_register` | `squuad_cert_user_signature_register` |
| `get_signature_section` | `squuad_cert_get_signature_section` |
| `get_signature_section_fgu` | `squuad_cert_get_signature_section_fgu` |
| `load_signatures_data` | `squuad_cert_load_signatures_data` |
| `modal_document_automatic` | `squuad_cert_modal_document_automatic` |
| `modal_enrollment_student` | `squuad_cert_modal_enrollment_student` |
| `modal_missing_student` | `squuad_cert_modal_missing_student` |

### Constantes, tablas, opciones, hooks, capacidades, acciones y textos

| Antes | Ahora |
|---|---|
| `'edusig_'` | `'squuad_cert_chain_'` |
| `'edusig_rc_'` | `'squuad_cert_rc_'` |
| `'edusig_req_'` | `'squuad_cert_req_'` |
| `'edusystem-documents-to-sign'` | `'squuad-cert-documents-to-sign'` |
| `'edusystem-my-signature'` | `'squuad-cert-my-signature'` |
| `'edusystem-signature-integrity'` | `'squuad-cert-signature-integrity'` |
| `'edusystem-signer-invitation\|'` | `'squuad-cert-signer-invitation\|'` |
| `edusystem firmas verificar` | `squuad-cert firmas verificar` |
| `edusystem_batch` | `squuad_cert_batch` |
| `edusystem_batch_notice_` | `squuad_cert_batch_notice_` |
| `edusystem_book` | `squuad_cert_book` |
| `edusystem_book_entries` | `squuad_cert_book_entries` |
| `EDUSYSTEM_CERTIFICATION_PATH` | `SQUUAD_CERT_MODULE_PATH` |
| `EDUSYSTEM_CERTIFICATION_URL` | `SQUUAD_CERT_MODULE_URL` |
| `edusystem_convert_missing_letter` | `squuad_cert_convert_missing_letter` |
| `EDUSYSTEM_DOCUMENT_CONDITIONS_OPTION` | `SQUUAD_CERT_DOCUMENT_CONDITIONS_OPTION` |
| `edusystem_document_fields` | `squuad_cert_document_fields` |
| `edusystem_document_request_conditions` | `squuad_cert_document_request_conditions` |
| `edusystem_document_signing_policies` | `squuad_cert_signing_policies` |
| `edusystem_document_signing_slots` | `squuad_cert_signing_slots` |
| `edusystem_documents_php_migrated` | `squuad_cert_documents_php_migrated` |
| `edusystem_documents_php_pending` | `squuad_cert_documents_php_pending` |
| `edusystem_holder_batch_confirm` | `squuad_cert_holder_batch_confirm` |
| `edusystem_holder_batch_confirm_` | `squuad_cert_holder_batch_confirm_` |
| `edusystem_holder_batch_prepare` | `squuad_cert_holder_batch_prepare` |
| `edusystem_issue_book_entry` | `squuad_cert_issue_book_entry` |
| `edusystem_issue_document` | `squuad_cert_issue_document` |
| `edusystem_issue_document_` | `squuad_cert_issue_document_` |
| `edusystem_issued` | `squuad_cert_issued` |
| `edusystem_issued_book_decision` | `squuad_cert_issued_book_decision` |
| `edusystem_issued_book_decision_` | `squuad_cert_issued_book_decision_` |
| `edusystem_issued_decline` | `squuad_cert_issued_decline` |
| `edusystem_issued_decline_` | `squuad_cert_issued_decline_` |
| `EDUSYSTEM_ISSUED_PREFIX` | `SQUUAD_CERT_ISSUED_PREFIX` |
| `edusystem_legacy_signatures_dismiss` | `squuad_cert_legacy_signatures_dismiss` |
| `EDUSYSTEM_LEGACY_SIGNATURES_DISMISSED` | `SQUUAD_CERT_LEGACY_SIGNATURES_DISMISSED` |
| `edusystem_legacy_signatures_notice_dismissed` | `squuad_cert_legacy_signatures_notice_dismissed` |
| `EDUSYSTEM_LEGACY_SIGNATURES_NOTIFIED` | `SQUUAD_CERT_LEGACY_SIGNATURES_NOTIFIED` |
| `edusystem_legacy_signatures_notified` | `squuad_cert_legacy_signatures_notified` |
| `edusystem_manage_signature_integrity` | `squuad_cert_manage_signature_integrity` |
| `edusystem_manage_signers` | `squuad_cert_manage_signers` |
| `EDUSYSTEM_MANAGE_SIGNERS_CAP` | `SQUUAD_CERT_MANAGE_SIGNERS_CAP` |
| `edusystem_manage_signing_policies` | `squuad_cert_manage_signing_policies` |
| `EDUSYSTEM_MANAGE_SIGNING_POLICIES_CAP` | `SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP` |
| `EDUSYSTEM_MISSING_LETTER_ID` | `SQUUAD_CERT_MISSING_LETTER_ID` |
| `edusystem_pdf` | `squuad_cert_pdf` |
| `edusystem_save_my_signature` | `squuad_cert_save_my_signature` |
| `edusystem_save_signing_policy` | `squuad_cert_save_signing_policy` |
| `edusystem_sign` | `squuad_cert_sign` |
| `edusystem_sign_as_signer` | `squuad_cert_sign_as_signer` |
| `edusystem_sign_as_signer_` | `squuad_cert_sign_as_signer_` |
| `edusystem_sign_documents` | `squuad_cert_sign_documents` |
| `EDUSYSTEM_SIGN_DOCUMENTS_CAP` | `SQUUAD_CERT_SIGN_DOCUMENTS_CAP` |
| `edusystem_signature_` | `squuad_cert_signature_` |
| `EDUSYSTEM_SIGNATURE_BATCH_CONSENT` | `SQUUAD_CERT_SIGNATURE_BATCH_CONSENT` |
| `EDUSYSTEM_SIGNATURE_BATCH_MAX` | `SQUUAD_CERT_SIGNATURE_BATCH_MAX` |
| `EDUSYSTEM_SIGNATURE_BATCH_MINUTES` | `SQUUAD_CERT_SIGNATURE_BATCH_MINUTES` |
| `edusystem_signature_batches` | `squuad_cert_batches` |
| `edusystem_signature_chain` | `squuad_cert_chain` |
| `EDUSYSTEM_SIGNATURE_CONSENT_CURRENT` | `SQUUAD_CERT_SIGNATURE_CONSENT_CURRENT` |
| `EDUSYSTEM_SIGNATURE_CONTENT_MAX_BYTES` | `SQUUAD_CERT_SIGNATURE_CONTENT_MAX_BYTES` |
| `edusystem_signature_events` | `squuad_cert_events` |
| `EDUSYSTEM_SIGNATURE_INTEGRITY_CAP` | `SQUUAD_CERT_SIGNATURE_INTEGRITY_CAP` |
| `EDUSYSTEM_SIGNATURE_KEY_ID` | `SQUUAD_CERT_SIGNATURE_KEY_ID` |
| `EDUSYSTEM_SIGNATURE_KEYS` | `SQUUAD_CERT_SIGNATURE_KEYS` |
| `edusystem_signature_keys` | `squuad_cert_signature_keys` |
| `edusystem_signature_last_verification` | `squuad_cert_signature_last_verification` |
| `edusystem_signature_legacy_csv` | `squuad_cert_signature_legacy_csv` |
| `edusystem_signature_legacy_max_id` | `squuad_cert_signature_legacy_max_id` |
| `EDUSYSTEM_SIGNATURE_QR_SLOT` | `SQUUAD_CERT_SIGNATURE_QR_SLOT` |
| `EDUSYSTEM_SIGNATURE_REQUEST_CLOSED` | `SQUUAD_CERT_SIGNATURE_REQUEST_CLOSED` |
| `edusystem_signature_request_contents` | `squuad_cert_request_contents` |
| `EDUSYSTEM_SIGNATURE_REQUEST_OPEN` | `SQUUAD_CERT_SIGNATURE_REQUEST_OPEN` |
| `edusystem_signature_request_signers` | `squuad_cert_request_signers` |
| `edusystem_signature_requests` | `squuad_cert_requests` |
| `edusystem_signature_rotate_key` | `squuad_cert_signature_rotate_key` |
| `EDUSYSTEM_SIGNATURE_SLOT` | `SQUUAD_CERT_SIGNATURE_SLOT` |
| `EDUSYSTEM_SIGNATURE_TEMPLATE_VERSIONS` | `SQUUAD_CERT_TEMPLATE_VERSIONS` |
| `edusystem_signature_verify` | `squuad_cert_signature_verify` |
| `edusystem_signatures_revoked` | `squuad_cert_signatures_revoked` |
| `edusystem_signer` | `squuad_cert_signer` |
| `edusystem_signer_batch_confirm` | `squuad_cert_signer_batch_confirm` |
| `edusystem_signer_batch_confirm_` | `squuad_cert_signer_batch_confirm_` |
| `edusystem_signer_batch_prepare` | `squuad_cert_signer_batch_prepare` |
| `edusystem_signer_decline` | `squuad_cert_signer_decline` |
| `edusystem_signer_decline_` | `squuad_cert_signer_decline_` |
| `EDUSYSTEM_SIGNER_INBOX_PAGE` | `SQUUAD_CERT_SIGNER_INBOX_PAGE` |
| `edusystem_signer_invitation` | `squuad_cert_invitation` |
| `EDUSYSTEM_SIGNER_INVITATION_HOURS` | `SQUUAD_CERT_SIGNER_INVITATION_HOURS` |
| `edusystem_signer_invitations` | `squuad_cert_signer_invitations` |
| `edusystem_signer_invite_new` | `squuad_cert_signer_invite_new` |
| `edusystem_signer_needs_password` | `squuad_cert_signer_needs_password` |
| `EDUSYSTEM_SIGNER_PROFILE_CONSENT` | `SQUUAD_CERT_SIGNER_PROFILE_CONSENT` |
| `edusystem_signer_register_` | `squuad_cert_signer_register_` |
| `edusystem_signer_resend` | `squuad_cert_signer_resend` |
| `edusystem_signer_revoke` | `squuad_cert_signer_revoke` |
| `EDUSYSTEM_SIGNER_ROLE` | `SQUUAD_CERT_SIGNER_ROLE` |
| `edusystem_signer_status` | `squuad_cert_signer_status` |
| `edusystem_signers` | `squuad_cert_signers` |
| `edusystem_signers_notice_` | `squuad_cert_signers_notice_` |
| `EDUSYSTEM_SIGNERS_PAGE` | `SQUUAD_CERT_SIGNERS_PAGE` |
| `EDUSYSTEM_SIGNERS_PARENT` | `SQUUAD_CERT_SIGNERS_PARENT` |
| `edusystem_switched_from` | `squuad_cert_switched_from` |
| `edusystem_user_signatures` | `squuad_cert_signer_signatures` |

> 2026-10-04: retiradas `squuad_cert_missing_letter_conversion_available`, `squuad_cert_missing_letter_conversion_notice`,
> `squuad_cert_missing_letter_template`, `squuad_cert_missing_letter_convert_handle` y la acción
> `squuad_cert_convert_missing_letter` (la carta se crea como documento normal en Documentos).
