(function () {
  'use strict';

  // Helpers
  function getAdminData() {
    return {
      ajaxUrl: (window.FJGAdmin && window.FJGAdmin.ajaxUrl) || window.ajaxurl || '',
      nonce: (window.FJGAdmin && window.FJGAdmin.nonce) || ''
    };
  }

  function renderNotice(container, type, message) {
    const cls = type === 'success' ? 'notice-success' : (type === 'info' ? 'notice-info' : 'notice-error');
    container.innerHTML = `<div class="notice ${cls} inline"><p>${message}</p></div>`;
  }

  document.addEventListener('DOMContentLoaded', () => {
    const testButton = document.querySelector('#test-api-key');
    const apiKeyInput = document.querySelector('#flickr-api-key-input');
    const resultContainer = document.querySelector('#api-test-result');

    if (!testButton || !apiKeyInput || !resultContainer) {
      return;
    }

    testButton.addEventListener('click', () => {
      const apiKey = apiKeyInput.value.trim();
      const adminData = getAdminData();

      if (!apiKey) {
        renderNotice(resultContainer, 'error', 'Please enter an API key to test.');
        return;
      }

      testButton.disabled = true;
      testButton.textContent = 'Testing...';
      renderNotice(resultContainer, 'info', 'Testing API key...');

      const formData = new URLSearchParams();
      formData.append('action', 'test_flickr_api_key');
      formData.append('api_key', apiKey);
      formData.append('nonce', adminData.nonce);

      fetch(adminData.ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData,
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(response => {
        if (response && response.success) {
          const msg = (response.data && response.data.message) ? response.data.message : 'API key is valid and working!';
          renderNotice(resultContainer, 'success', msg);
        } else {
          const err = (response && response.data && response.data.message) ? response.data.message : 'API key test failed.';
          renderNotice(resultContainer, 'error', err);
        }
      })
      .catch(error => {
        console.error('API key test failed:', error);
        renderNotice(resultContainer, 'error', 'Failed to test API key. Please try again.');
      })
      .finally(() => {
        testButton.disabled = false;
        testButton.textContent = 'Test API Key';
      });
    });
  });
})();