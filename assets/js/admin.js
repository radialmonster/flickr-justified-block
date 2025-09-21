(function ($) {
  'use strict';

  // Helpers
  function getAdminData() {
    return {
      ajaxUrl: (window.FJGAdmin && FJGAdmin.ajaxUrl) || window.ajaxurl || '',
      nonce: (window.FJGAdmin && FJGAdmin.nonce) || ''
    };
  }

  function renderNotice($container, type, message) {
    var cls = type === 'success' ? 'notice-success' : (type === 'info' ? 'notice-info' : 'notice-error');
    $container.html('<div class="notice ' + cls + ' inline"><p>' + message + '</p></div>');
  }

  $(function () {
    $('#test-api-key').on('click', function () {
      var $button = $(this);
      var apiKey = $('#flickr-api-key-input').val().trim();
      var $result = $('#api-test-result');
      var data = getAdminData();

      if (!apiKey) {
        renderNotice($result, 'error', 'Please enter an API key to test.');
        return;
      }

      $button.prop('disabled', true).text('Testing...');
      renderNotice($result, 'info', 'Testing API key...');

      $.ajax({
        url: data.ajaxUrl,
        method: 'POST',
        data: {
          action: 'test_flickr_api_key',
          api_key: apiKey,
          nonce: data.nonce
        }
      })
        .done(function (response) {
          if (response && response.success) {
            var msg = (response.data && response.data.message) ? response.data.message : 'API key is valid and working!';
            renderNotice($result, 'success', msg);
          } else {
            var err = (response && response.data && response.data.message) ? response.data.message : 'API key test failed.';
            renderNotice($result, 'error', err);
          }
        })
        .fail(function () {
          renderNotice($result, 'error', 'Failed to test API key. Please try again.');
        })
        .always(function () {
          $button.prop('disabled', false).text('Test API Key');
        });
    });
  });
})(jQuery);
