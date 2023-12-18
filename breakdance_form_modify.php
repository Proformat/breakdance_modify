<?php
namespace Breakdance\Forms;
 
use Breakdance\Forms\Actions\Action;
use Breakdance\Forms\Actions\ActionProvider;
use function Breakdance\AJAX\get_nonce_key_for_ajax_requests;
use function Breakdance\Forms\Render\getFieldAttributes;
use const Breakdance\Filesystem\PHP_FILE_UPLOAD_ERROR_MESSAGES;
function handleSubmissionCustom($postId, $formId, $fields)
{
    $settings = getFormSettings($postId, $formId);

    if (!$settings) {
        return [
            'type' => 'error',
            'message' => 'An unexpected error occurred.'
        ];
    }

    $actions = getFormActions($settings);
    $form    = getFormData($settings['form']['fields'], $fields);
    $successMessage = $settings['form']['success_message'];
    $errorMessage = $settings['form']['error_message'];

    // Metadata
    $ip        = (string) ($_SERVER['REMOTE_ADDR'] ?? null);
    $referer   = wp_get_referer();
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $userId = get_current_user_id();

    // User access
    $breakdancePermissions = \Breakdance\Permissions\getUserPermission();
    $hasFullAccess = $breakdancePermissions && $breakdancePermissions['slug'] === 'full';

    // reCAPTCHA v3 challenge
    $recaptchaEnabled = $settings['advanced']['recaptcha']['enabled'] ?? false;
    if ($recaptchaEnabled) {
        $token   = (string) ($_POST['recaptcha_token'] ?? null);
        if (!$token) {
            return [
                'type' => 'error',
                'message' => $hasFullAccess ? '[reCAPTCHA] Error retrieving token.' : $errorMessage
            ];
        }
        $verified = \Breakdance\Forms\Recaptcha\verify($token, $ip, 'breakdance_submit', $settings['advanced']['recaptcha']['api_key_input']['apiKey']);

        if (!$verified) {
            return [
                'type' => 'error',
                'message' => $hasFullAccess ? '[reCAPTCHA] Invalid challenge. Please reload and try again.' : $errorMessage
            ];
        }
    }

    // Validate honeypot field
    $honeypotEnabled = $settings['advanced']['honeypot_enabled'] ?? false;
    if ($honeypotEnabled) {
        if (array_key_exists('hpname', $fields) && !empty($fields['hpname'])) {
            // failed the honeypot test. Ignore this
            // submission but return a success response
            return [
                'type' => 'success',
                'message' => $successMessage,
            ];
        }
    }

    $uploads = [];
    if (array_key_exists('fields', $_FILES)) {
        /** @psalm-suppress MixedArgument */
        $uploads = getFilesNormalized($_FILES['fields']);
    }

    // Validate form. Validators available: Required, email, and files.
    $validation = validateFormData($form, $uploads);

    if (is_wp_error($validation)) {
        /** @psalm-suppress PossiblyInvalidMethodCall */
        return [
            'type' => 'error',
            'message' => $validation->get_error_message()
        ];
    }

    // Upload files if any is present. Runs after all validations
    // We don't want to allow spam files in our server.
    $files = handleUploadedFiles($formId, $uploads, $settings);
    $storeUploadedFiles = $settings['actions']['store_submission']['store_files'] ?? false;
    if (!empty($files) && $storeUploadedFiles) {
        // If we have files, assign the URLs to
        // the value for both $form and $extra
        foreach ($form as &$field) {
            $fieldId = getIdFromField($field);
            if ($field['type'] === 'file' && array_key_exists($fieldId, $files)) {
                $commaSeparatedFileUrls = implode(', ', array_map(static function($file) use ($postId, $formId, $fieldId) {
                    return getSecureFileUrl($postId, $formId, $fieldId, $file['url'], false);
                }, $files[$fieldId]));
                $fields[$fieldId] =  $commaSeparatedFileUrls;
                $field['value'] =  $commaSeparatedFileUrls;
            }
        }
        unset($field);
    }

    // Run all actions set for this form
    /** @var FormExtra $extra */
    $extra = [
        'formId'  => $formId,
        'postId'  => $postId,
        'fields'   => $fields,
        'uploads'    => $uploads,
        'files'    => $files,
        'ip'      => $ip,
        'referer' => $referer,
        'userAgent' => $userAgent,
        'userId' => $userId,
    ];

    $response = executeActions($actions, [$form, $settings, $extra]);
    /** @var int|null $storedId */
    $storedId = $response['store_submission']['id'] ?? null;

    if ($storedId) {
        unset($response['store_submission']); // We don't care for the 'store submission' action.
        \Breakdance\Forms\Submission\saveActionsLog($storedId, $response);
    } else {
        // If there is no stored submission, delete any uploaded files
        // If there is a stored submission, the stored submission action
        // will handle any necessary file cleanup
        cleanUpFiles($files);
    }

    // Remove context data from the request response
    // we don't want to return this to the user
    foreach ($response as &$responseItem) {
        unset($responseItem['context']);
    }
    unset($responseItem);

    // Show errors if any is present and user is admin
    $actionsErrors = getCustomActionsErrors($response);
    if (!empty($actionsErrors)) {
        return [
            'type' => 'error',
            'message' => implode("<br>", $actionsErrors)
        ];
    }

    // Otherwise return a success message.
    return [
        'type' => 'success',
        'message' => $successMessage,
    ];
}


function getCustomActionsErrors($responses)
{
    /** @var ActionError[] $errors */
    $errors = array_values(array_filter(array_map(static function($response, $slug) {
        if ($response['type'] !== 'error') {
            return false;
        }
        $action = ActionProvider::getInstance()->getActionBySlug((string) $slug);
        return $response['message'];
    }, $responses, array_keys($responses))));

    return $errors;
}