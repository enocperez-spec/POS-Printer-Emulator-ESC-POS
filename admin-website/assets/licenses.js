(() => {
  const search = document.getElementById('license-search');
  const filter = document.getElementById('status-filter');
  const rows = [...document.querySelectorAll('#license-rows tr[data-status]')];
  const count = document.getElementById('license-count');
  const updateLicenses = () => {
    const query = (search?.value || '').trim().toLowerCase();
    const status = filter?.value || 'all';
    let visible = 0;
    rows.forEach((row) => {
      const show = (!query || row.textContent.toLowerCase().includes(query)) && (status === 'all' || row.dataset.status === status);
      row.hidden = !show;
      if (show) visible += 1;
    });
    if (count) count.textContent = `Showing ${visible} licenses`;
  };
  search?.addEventListener('input', updateLicenses);
  filter?.addEventListener('change', updateLicenses);

  const trialSearch = document.getElementById('trial-search');
  const trialRows = [...document.querySelectorAll('#trial-rows tr:not(.empty-row)')];
  const trialCount = document.getElementById('trial-count');
  trialSearch?.addEventListener('input', () => {
    const query = trialSearch.value.trim().toLowerCase();
    let visible = 0;
    trialRows.forEach((row) => {
      const show = !query || row.textContent.toLowerCase().includes(query);
      row.hidden = !show;
      if (show) visible += 1;
    });
    if (trialCount) trialCount.textContent = `Showing ${visible} Trial installations`;
  });

  const issueForm = document.getElementById('license-issue-form');
  const complimentaryDuration = document.getElementById('complimentary-duration');
  const complimentaryExpirationField = document.getElementById('complimentary-expiration-field');
  const complimentaryExpiration = document.getElementById('complimentary-expiration');
  const updateComplimentaryExpiration = () => {
    const expires = complimentaryDuration?.value === 'expires';
    if (complimentaryExpirationField) complimentaryExpirationField.hidden = !expires;
    if (complimentaryExpiration) {
      complimentaryExpiration.required = expires;
      if (!expires) complimentaryExpiration.value = '';
    }
  };
  complimentaryDuration?.addEventListener('change', updateComplimentaryExpiration);
  updateComplimentaryExpiration();

  issueForm?.addEventListener('submit', () => {
    const submit = issueForm.querySelector('button[type="submit"]');
    if (!submit) return;
    submit.disabled = true;
    submit.textContent = 'Processing…';
  });

  const dialog = document.getElementById('license-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') {
    document.querySelectorAll('.manage-license').forEach((button) => {
      button.disabled = true;
      button.title = 'License controls require a current browser.';
    });
    return;
  }

  const manageView = document.getElementById('license-manage-view');
  const trialView = document.getElementById('trial-manage-view');
  const confirmView = document.getElementById('license-confirm-view');
  const targetTier = document.getElementById('manage-target-tier');
  const targetCustomer = document.getElementById('manage-target-customer');
  const trialTargetTier = document.getElementById('trial-target-tier');
  const reasonField = document.getElementById('reason-field');
  const reasonInput = document.getElementById('action-reason');
  const verificationField = document.getElementById('verification-field');
  const customerVerified = document.getElementById('customer-verified');
  const phraseField = document.getElementById('phrase-field');
  const phraseInput = document.getElementById('action-confirmation-phrase');
  const requiredPhrase = document.getElementById('required-phrase');
  const confirmSubmit = document.getElementById('confirm-submit');
  const actionForm = document.getElementById('license-action-form');
  let selected = null;
  let returnFocus = null;
  let preparedActionButton = null;
  let submitting = false;

  const setText = (id, value) => {
    const element = document.getElementById(id);
    if (element) element.textContent = value || '—';
  };

  const showView = (view) => {
    manageView.hidden = view !== 'manage';
    trialView.hidden = view !== 'trial';
    confirmView.hidden = view !== 'confirm';
    dialog.setAttribute('aria-labelledby', view === 'confirm' ? 'confirm-title' : 'license-dialog-title');
    dialog.setAttribute('aria-describedby', view === 'confirm' ? 'confirm-description' : (view === 'trial' ? 'trial-dialog-description' : 'license-dialog-description'));
  };

  const updateTierButton = () => {
    const button = dialog.querySelector('[data-prepare-action="change_tier"]');
    if (button) button.disabled = !selected || targetTier.value === selected.tier;
  };

  document.querySelectorAll('.manage-license:not(.trial-upgrade)').forEach((button) => {
    button.addEventListener('click', () => {
      selected = {
        kind: 'license',
        licenseId: button.dataset.licenseId,
        customer: button.dataset.customer,
        email: button.dataset.email,
        tier: button.dataset.tier,
        status: button.dataset.status,
        state: button.dataset.controlState,
        maintenanceStatus: button.dataset.maintenanceStatus,
        maintenanceExpires: button.dataset.maintenanceExpires,
        rowVersion: button.dataset.rowVersion,
      };
      returnFocus = button;
      setText('manage-customer', selected.customer);
      setText('manage-email', selected.email);
      setText('manage-license-id', selected.licenseId);
      setText('manage-tier', selected.tier);
      setText('manage-status', selected.status);
      setText('manage-maintenance-status', selected.maintenanceStatus);
      setText('manage-maintenance-expires', `Through ${selected.maintenanceExpires} UTC`);
      const tierOrder = ['Lite', 'Pro', 'Enterprise'];
      const currentTierIndex = tierOrder.indexOf(selected.tier);
      targetTier.value = currentTierIndex >= 0 && currentTierIndex < tierOrder.length - 1
        ? tierOrder[currentTierIndex + 1]
        : 'Pro';
      dialog.querySelector('.tier-action').hidden = selected.state !== 'Enabled';
      dialog.querySelector('[data-prepare-action="change_tier"]').hidden = selected.state !== 'Enabled';
      dialog.querySelector('[data-prepare-action="deactivate"]').hidden = selected.state !== 'Enabled';
      dialog.querySelector('[data-prepare-action="reactivate"]').hidden = selected.state !== 'Deactivated';
      dialog.querySelector('[data-prepare-action="revoke"]').hidden = !['Enabled', 'Deactivated'].includes(selected.state);
      dialog.querySelector('[data-prepare-action="delete"]').hidden = selected.state === 'Deleted';
      dialog.querySelector('.maintenance-actions').hidden = selected.state !== 'Enabled';
      dialog.querySelector('[data-prepare-action="extend_maintenance"]').hidden = selected.state !== 'Enabled';
      dialog.querySelector('[data-prepare-action="revoke_maintenance"]').hidden = selected.state !== 'Enabled' || selected.maintenanceStatus === 'revoked';
      dialog.querySelector('[data-prepare-action="restore_maintenance"]').hidden = selected.state !== 'Enabled' || selected.maintenanceStatus !== 'revoked';
      showView('manage');
      updateTierButton();
      dialog.showModal();
      (selected.state === 'Enabled' ? targetTier : dialog.querySelector('[data-prepare-action]:not([hidden])'))?.focus();
    });
  });

  document.querySelectorAll('.trial-upgrade').forEach((button) => {
    button.addEventListener('click', () => {
      selected = {
        kind: 'trial',
        installationId: button.dataset.installationId,
        customer: button.dataset.customer,
        email: button.dataset.email,
      };
      returnFocus = button;
      setText('trial-customer', selected.customer);
      setText('trial-email', selected.email);
      setText('trial-installation-id', selected.installationId);
      trialTargetTier.value = 'Lite';
      showView('trial');
      dialog.showModal();
      trialTargetTier.focus();
    });
  });

  targetTier?.addEventListener('change', updateTierButton);

  const actionCopy = (action, nextTier) => {
    const copies = {
      change_tier: {
        title: 'Confirm license type change',
        description: `Change ${selected.customer} from ${selected.tier} to ${nextTier}? Registered computers will synchronize the new entitlement automatically.`,
        label: `Change to ${nextTier}`,
      },
      reassign_customer: {
        title: 'Confirm customer reassignment',
        description: 'Release the current registered computer and move this license to the selected verified customer account? This action is audited.',
        label: 'Reassign license',
      },
      deactivate: {
        title: 'Confirm deactivation',
        description: 'Deactivate this license and release its registered computer? The application will synchronize the change promptly when online.',
        label: 'Confirm deactivation',
      },
      reactivate: {
        title: 'Confirm reactivation',
        description: 'Return this deactivated license to Active? The customer can link an eligible computer again.',
        label: 'Confirm reactivation',
      },
      revoke: {
        title: 'Confirm permanent revocation',
        description: 'Permanently revoke this entitlement and its device assignment? This cannot be reversed through the ordinary License Manager.',
        label: 'Permanently revoke',
      },
      delete: {
        title: 'Confirm license deletion',
        description: 'Delete this license from normal License Manager views? The audit tombstone and device history will remain, but this action cannot be undone.',
        label: 'Delete license record',
      },
      extend_maintenance: {
        title: 'Confirm maintenance extension',
        description: `Extend ${selected.customer}'s Application Maintenance and Support by one year? Active coverage is extended from its current end date; lapsed coverage starts today.`,
        label: 'Extend maintenance one year',
      },
      revoke_maintenance: {
        title: 'Confirm maintenance revocation',
        description: 'Disable update downloads and technical support for this license? The permanent application license and all purchased features will continue working.',
        label: 'Revoke maintenance access',
      },
      restore_maintenance: {
        title: 'Confirm maintenance restoration',
        description: 'Restore maintenance access through the existing coverage expiration date? This does not extend the expiration date.',
        label: 'Restore maintenance access',
      },
      upgrade_trial: {
        title: 'Confirm Trial account upgrade',
        description: `Assign a ${nextTier} entitlement to the verified account for this Trial installation? The application will synchronize it automatically after linking.`,
        label: `Assign ${nextTier} license`,
      },
    };
    return copies[action];
  };

  const updateDestructiveValidation = () => {
    const action = document.getElementById('action-name').value;
    if (action === 'upgrade_trial') {
      confirmSubmit.disabled = !customerVerified.checked;
      return;
    }
    if (action === 'reassign_customer') {
      confirmSubmit.disabled = !targetCustomer.value || reasonInput.value.trim().length < 3;
      return;
    }
    if (!['revoke', 'delete'].includes(action)) {
      confirmSubmit.disabled = false;
      return;
    }
    confirmSubmit.disabled = phraseInput.value.trim().toUpperCase() !== action.toUpperCase() || reasonInput.value.trim().length < 3;
  };

  dialog.querySelectorAll('[data-prepare-action]').forEach((button) => {
    button.addEventListener('click', () => {
      preparedActionButton = button;
      const action = button.dataset.prepareAction;
      const usesTier = ['change_tier', 'upgrade_trial'].includes(action);
      const nextTier = usesTier ? (action === 'upgrade_trial' ? trialTargetTier.value : targetTier.value) : '';
      const copy = actionCopy(action, nextTier);
      setText('confirm-title', copy.title);
      setText('confirm-description', copy.description);
      setText('confirm-customer', selected.customer);
      setText('confirm-license', selected.kind === 'trial' ? selected.installationId : selected.licenseId);
      document.getElementById('action-name').value = action;
      document.getElementById('action-license-id').value = selected.licenseId || '';
      document.getElementById('action-installation-id').value = selected.installationId || '';
      document.getElementById('action-row-version').value = selected.rowVersion || '';
      document.getElementById('action-target-tier').value = nextTier || '';
      document.getElementById('action-target-customer').value = action === 'reassign_customer' ? targetCustomer.value : '';
      reasonInput.value = '';
      phraseInput.value = '';
      customerVerified.checked = false;
      const trialUpgrade = action === 'upgrade_trial';
      verificationField.hidden = !trialUpgrade;
      customerVerified.required = trialUpgrade;
      const destructive = ['revoke', 'delete', 'reassign_customer'].includes(action);
      const phraseRequired = ['revoke', 'delete'].includes(action);
      reasonField.hidden = !destructive;
      reasonInput.required = destructive;
      phraseField.hidden = !phraseRequired;
      phraseInput.required = phraseRequired;
      requiredPhrase.textContent = phraseRequired ? action.toUpperCase() : '';
      confirmSubmit.textContent = copy.label;
      confirmSubmit.classList.toggle('danger', destructive);
      showView('confirm');
      updateDestructiveValidation();
      (destructive ? reasonInput : (trialUpgrade ? customerVerified : confirmSubmit)).focus();
    });
  });

  phraseInput?.addEventListener('input', updateDestructiveValidation);
  reasonInput?.addEventListener('input', updateDestructiveValidation);
  customerVerified?.addEventListener('change', updateDestructiveValidation);
  dialog.querySelector('[data-dialog-back]')?.addEventListener('click', () => {
    if (submitting) return;
    showView(selected?.kind === 'trial' ? 'trial' : 'manage');
    preparedActionButton?.focus();
  });
  dialog.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => {
    if (!submitting) dialog.close();
  }));
  dialog.addEventListener('cancel', (event) => {
    if (submitting) event.preventDefault();
  });
  dialog.addEventListener('close', () => {
    showView('manage');
    selected = null;
    preparedActionButton = null;
    submitting = false;
    dialog.removeAttribute('aria-busy');
    returnFocus?.focus();
    returnFocus = null;
  });
  actionForm?.addEventListener('submit', () => {
    submitting = true;
    dialog.setAttribute('aria-busy', 'true');
    dialog.querySelectorAll('button').forEach((control) => { control.disabled = true; });
    confirmSubmit.disabled = true;
    confirmSubmit.textContent = 'Processing…';
  });
})();
