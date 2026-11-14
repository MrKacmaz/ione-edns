<?php

/*
|--------------------------------------------------------------------------
| Application Validation Messages
|--------------------------------------------------------------------------
|
| Messages of the application's own validation rules. Laravel merges this
| file over its built-in validation messages, so only custom keys belong here.
|
*/

return [

    'notification_recipient' => 'The :attribute must be :format for the :channel channel.',

    'notification_content_length' => 'The :attribute may not be greater than :max characters for the :channel channel.',

    'pagination_cursor' => 'The :attribute is invalid. Use the cursor from the links of the previous page.',

    'notification_template_placeholders' => 'The :attribute may only use placeholders like {{ name }}, whose names consist of letters, digits and underscores.',

    'notification_template_content_length' => 'The :attribute may not be greater than :max characters for the :channel channel, not counting its placeholders.',

    'notification_template_not_found' => 'The :attribute ":name" does not exist.',

    'notification_template_channel' => 'The :attribute ":name" is for the :template_channel channel, not the :channel channel.',

    'notification_template_variables' => 'The :attribute must give a text or number value to: :variables.',

    'notification_template_rendered_length' => 'The content rendered from the :attribute may not be greater than :max characters for the :channel channel.',

];
