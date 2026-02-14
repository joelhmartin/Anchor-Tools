/* ══════════════════════════════════════════════════
   CTM Form Builder — Admin Visual Builder
   Plugin: Anchor CTM Forms

   jQuery IIFE + jQuery UI Sortable.
   State-driven: config object → hidden textarea → PHP.
   ══════════════════════════════════════════════════ */
(function ($) {
  'use strict';

  if (typeof CTM_BUILDER === 'undefined') return;

  /* ── Core field names that CTM expects ── */
  var CORE_FIELDS = ['caller_name', 'email', 'phone_number', 'phone', 'country_code'];

  /* ── Field type definitions ── */
  var FIELD_TYPES = {
    text:      { label: 'Text',      icon: 'dashicons-editor-textcolor', group: 'input' },
    email:     { label: 'Email',     icon: 'dashicons-email',            group: 'input' },
    tel:       { label: 'Phone',     icon: 'dashicons-phone',            group: 'input' },
    number:    { label: 'Number',    icon: 'dashicons-calculator',       group: 'input' },
    url:       { label: 'URL',       icon: 'dashicons-admin-links',      group: 'input' },
    textarea:  { label: 'Textarea',  icon: 'dashicons-editor-paragraph', group: 'input' },
    select:    { label: 'Select',    icon: 'dashicons-arrow-down-alt2',  group: 'input' },
    checkbox:  { label: 'Checkbox',  icon: 'dashicons-yes-alt',          group: 'input' },
    radio:     { label: 'Radio',     icon: 'dashicons-marker',           group: 'input' },
    hidden:    { label: 'Hidden',    icon: 'dashicons-hidden',           group: 'input' },
    heading:   { label: 'Heading',   icon: 'dashicons-heading',          group: 'layout' },
    paragraph: { label: 'Paragraph', icon: 'dashicons-editor-alignleft', group: 'layout' },
    divider:   { label: 'Divider',   icon: 'dashicons-minus',            group: 'layout' }
  };

  var OPERATORS = [
    { value: 'equals',       label: 'equals' },
    { value: 'not_equals',   label: 'not equals' },
    { value: 'contains',     label: 'contains' },
    { value: 'is_empty',     label: 'is empty' },
    { value: 'is_not_empty', label: 'is not empty' },
    { value: 'greater_than', label: 'greater than' },
    { value: 'less_than',    label: 'less than' }
  ];

  /* ── Utility ── */
  function uid() {
    return 'f_' + Math.random().toString(36).substr(2, 8);
  }

  function esc(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
  }

  /* ── State ── */
  var config = { settings: {}, fields: [] };
  var activeStep = 0;
  var previewTimer = null;
  var selectedFieldId = null;
  var accordionState = {
    formSettings: true,
    multiStep: false,
    scoring: false,
    fieldGeneral: true,
    fieldOptions: false,
    fieldAdvanced: false,
    fieldConditions: false
  };

  /* ── DOM refs ── */
  var $canvas, $configInput, $previewFrame, $modeInput, $sidebar;

  /* ═══════════════════════════════════════════════
     INIT
     ═══════════════════════════════════════════════ */
  $(function () {
    // Only on ctm_form_variant post type
    if ($('#ctm-tab-builder').length === 0) return;

    $canvas      = $('#ctm-field-canvas');
    $configInput = $('#ctm_form_config');
    $previewFrame = $('#ctm-builder-preview-frame');
    $modeInput   = $('#ctm_form_mode');
    $sidebar     = $('#ctm-builder-sidebar');

    // Load existing config
    var raw = $configInput.val();
    if (raw) {
      try {
        var parsed = JSON.parse(raw);
        if (parsed && parsed.fields) {
          config = parsed;
        }
      } catch (e) { /* ignore */ }
    }
    ensureDefaults();

    // Tab switching
    initTabs();

    // Palette
    initPalette();

    // Sortable
    initSortable();

    // Settings panel events
    initSettingsEvents();

    // Multi-step controls
    initMultiStepControls();

    // Initial render
    renderFieldList();
    renderSidebar();
    renderMultiStepControls();
    syncConfig();

    // Initial sidebar visibility
    if ($modeInput.val() !== 'builder') {
      $('#anchor_ctm_builder_sidebar').hide();
    }

    // Reactor tab inline JS (generate btn, copy btn, analytics toggle, multi-step toggle)
    initReactorTabLegacy();
  });

  /* ═══════════════════════════════════════════════
     DEFAULTS
     ═══════════════════════════════════════════════ */
  function ensureDefaults() {
    var s = config.settings;
    if (!s.labelStyle)      s.labelStyle = 'above';
    if (!s.submitText)      s.submitText = 'Submit';
    if (!s.successMessage)  s.successMessage = "Thanks! We'll be in touch shortly.";
    if (s.multiStep === undefined) s.multiStep = false;
    if (s.progressBar === undefined) s.progressBar = true;
    if (!s.titlePage) s.titlePage = { enabled: false, heading: '', description: '', buttonText: 'Get Started' };
    if (!s.scoring) s.scoring = { enabled: false, showTotal: false, totalLabel: 'Your Score', sendAs: 'custom_total_score' };
    if (!config.fields) config.fields = [];
  }

  /* ═══════════════════════════════════════════════
     TAB SWITCHING
     ═══════════════════════════════════════════════ */
  function initTabs() {
    $(document).on('click', '.ctm-tab-btn', function () {
      var target = $(this).data('tab');
      $('.ctm-tab-btn').removeClass('active');
      $(this).addClass('active');
      $('.ctm-tab-panel').removeClass('active');
      $('#ctm-tab-' + target).addClass('active');
      $modeInput.val(target === 'builder' ? 'builder' : 'reactor');

      // Show/hide sidebar metabox
      if (target === 'builder') {
        $('#anchor_ctm_builder_sidebar').show();
      } else {
        $('#anchor_ctm_builder_sidebar').hide();
      }
    });
  }

  /* ═══════════════════════════════════════════════
     PALETTE (click to add)
     ═══════════════════════════════════════════════ */
  function initPalette() {
    $(document).on('click', '.ctm-palette-btn', function () {
      var type = $(this).data('type');
      addField(type);
    });
  }

  /* ═══════════════════════════════════════════════
     SORTABLE
     ═══════════════════════════════════════════════ */
  function initSortable() {
    $canvas.sortable({
      handle: '.ctm-drag-handle',
      placeholder: 'ui-sortable-placeholder',
      tolerance: 'pointer',
      update: function () {
        // Reorder config.fields to match DOM
        var newOrder = [];
        $canvas.children('.ctm-field-row').each(function () {
          var id = $(this).data('field-id');
          var f = findField(id);
          if (f) newOrder.push(f);
        });
        config.fields = newOrder;
        syncConfig();
      }
    });
  }

  /* ═══════════════════════════════════════════════
     FIELD CRUD
     ═══════════════════════════════════════════════ */
  function getFieldDefaults(type) {
    var f = {
      id: uid(),
      type: type,
      label: FIELD_TYPES[type] ? FIELD_TYPES[type].label : 'Field',
      name: '',
      placeholder: '',
      helpText: '',
      defaultValue: '',
      required: false,
      isCustom: true,
      width: 'full',
      labelStyle: 'inherit',
      cssClass: '',
      step: 0,
      conditions: [],
      conditionLogic: 'all',
      logVisible: false
    };

    // Type-specific defaults
    switch (type) {
      case 'email':
        f.name = 'email';
        f.label = 'Email';
        f.isCustom = false;
        f.placeholder = 'your@email.com';
        break;
      case 'tel':
        f.name = 'phone_number';
        f.label = 'Phone';
        f.isCustom = false;
        f.required = true;
        break;
      case 'number':
        f.min = null;
        f.max = null;
        f.numStep = null;
        break;
      case 'select':
      case 'checkbox':
      case 'radio':
        f.options = [
          { label: 'Option 1', value: 'opt1', score: 0 },
          { label: 'Option 2', value: 'opt2', score: 0 }
        ];
        break;
      case 'hidden':
        f.label = 'Hidden Field';
        break;
      case 'heading':
        f.label = 'Section Heading';
        break;
      case 'paragraph':
        f.label = 'Paragraph text goes here.';
        break;
      case 'divider':
        f.label = '';
        break;
    }

    // Auto-generate field name
    if (!f.name && type !== 'heading' && type !== 'paragraph' && type !== 'divider') {
      f.name = 'custom_' + type + '_' + f.id.substr(2, 4);
    }

    return f;
  }

  function addField(type) {
    var f = getFieldDefaults(type);
    // Assign to active step if multi-step
    if (config.settings.multiStep) {
      f.step = activeStep;
    }
    config.fields.push(f);
    selectedFieldId = f.id;
    renderFieldList();
    renderSidebar();
    syncConfig();
  }

  function removeField(id) {
    config.fields = config.fields.filter(function (f) { return f.id !== id; });
    if (selectedFieldId === id) {
      selectedFieldId = null;
    }
    renderFieldList();
    renderSidebar();
    syncConfig();
  }

  function duplicateField(id) {
    var orig = findField(id);
    if (!orig) return;
    var copy = JSON.parse(JSON.stringify(orig));
    copy.id = uid();
    copy.name = orig.name ? orig.name + '_copy' : '';
    // Insert after original
    var idx = config.fields.indexOf(orig);
    config.fields.splice(idx + 1, 0, copy);
    selectedFieldId = copy.id;
    renderFieldList();
    renderSidebar();
    syncConfig();
  }

  function findField(id) {
    for (var i = 0; i < config.fields.length; i++) {
      if (config.fields[i].id === id) return config.fields[i];
    }
    return null;
  }

  function updateField(id, key, val) {
    var f = findField(id);
    if (!f) return;
    f[key] = val;

    // Auto-detect core fields when name changes
    if (key === 'name') {
      f.isCustom = CORE_FIELDS.indexOf(val) === -1;
    }

    syncConfig();
  }

  /* ═══════════════════════════════════════════════
     RENDER FIELD LIST
     ═══════════════════════════════════════════════ */
  function renderFieldList() {
    $canvas.empty();

    var fields = config.fields;

    // Filter by step if multi-step
    if (config.settings.multiStep) {
      fields = fields.filter(function (f) { return (f.step || 0) === activeStep; });
    }

    if (fields.length === 0) {
      $canvas.addClass('empty').html('<span>Click a button above to add fields</span>');
      return;
    }

    $canvas.removeClass('empty');

    fields.forEach(function (f) {
      $canvas.append(renderFieldRow(f));
    });
  }

  function renderFieldRow(f) {
    var typeDef = FIELD_TYPES[f.type] || { label: f.type, icon: 'dashicons-admin-generic' };
    var badges = '';

    if (f.width && f.width !== 'full') {
      badges += '<span class="ctm-badge ctm-badge-width">' + esc(f.width) + '</span>';
    }
    if (f.required) {
      badges += '<span class="ctm-badge ctm-badge-required">*</span>';
    }
    if (config.settings.multiStep && typeof f.step !== 'undefined') {
      badges += '<span class="ctm-badge ctm-badge-step">Step ' + (f.step + 1) + '</span>';
    }
    if (f.conditions && f.conditions.length > 0) {
      badges += '<span class="ctm-badge ctm-badge-conditions">Conditional</span>';
    }
    if (!f.isCustom) {
      badges += '<span class="ctm-badge ctm-badge-custom">Core</span>';
    }

    var nameDisplay = f.name ? '(' + esc(f.name) + ')' : '';
    var labelDisplay = f.label || (f.type === 'divider' ? '\u2014 Divider \u2014' : 'Untitled');

    var html = '<div class="ctm-field-row' + (f.id === selectedFieldId ? ' selected' : '') + '" data-field-id="' + f.id + '">';
    html += '<div class="ctm-field-header">';
    html += '<span class="ctm-drag-handle"><span class="dashicons dashicons-menu"></span></span>';
    html += '<span class="ctm-field-type-badge">' + esc(typeDef.label) + '</span>';
    html += '<span class="ctm-field-label-text">' + esc(labelDisplay) + '</span>';
    html += '<span class="ctm-field-name-text">' + esc(nameDisplay) + '</span>';
    html += '<span class="ctm-field-badges">' + badges + '</span>';
    html += '<span class="ctm-field-actions">';
    html += '<button type="button" class="ctm-btn-dup" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>';
    html += '<button type="button" class="ctm-btn-delete" title="Delete"><span class="dashicons dashicons-trash"></span></button>';
    html += '</span>';
    html += '</div>';
    html += '</div>';

    return html;
  }

  /* ═══════════════════════════════════════════════
     SIDEBAR RENDERING
     ═══════════════════════════════════════════════ */
  function renderSidebar() {
    if (!$sidebar || !$sidebar.length) return;

    var html = '';

    // Form-level accordions (always shown)
    html += renderAccordion('formSettings', 'Form Settings', renderFormSettingsContent());
    html += renderAccordion('multiStep', 'Multi-Step', renderMultiStepContent());
    html += renderAccordion('scoring', 'Scoring', renderScoringContent());

    // Field settings (only when a field is selected)
    if (selectedFieldId) {
      var f = findField(selectedFieldId);
      if (f) {
        html += '<div class="ctm-sidebar-field-header">Field: \u201c' + esc(f.label || f.type) + '\u201d</div>';
        html += '<div class="ctm-sidebar-field" data-field-id="' + f.id + '">';
        html += renderFieldSettingsSidebar(f);
        html += '</div>';
      }
    }

    $sidebar.html(html);

    // Init options sortable in sidebar
    if (selectedFieldId) {
      initOptionsSortable($sidebar);
    }
  }

  function renderAccordion(key, title, content) {
    var isOpen = !!accordionState[key];
    var cls = 'ctm-accordion' + (isOpen ? ' open' : '');
    return '<div class="' + cls + '" data-accordion="' + key + '">'
      + '<div class="ctm-accordion-header"><span class="toggle-arrow">&#9654;</span> ' + esc(title) + '</div>'
      + '<div class="ctm-accordion-body">' + content + '</div>'
      + '</div>';
  }

  function renderFormSettingsContent() {
    var s = config.settings;
    var html = '';

    html += '<label>Label Style</label>';
    html += '<select id="bs-labelStyle">';
    ['above', 'floating', 'hidden'].forEach(function (ls) {
      html += '<option value="' + ls + '"' + (s.labelStyle === ls ? ' selected' : '') + '>' + ls.charAt(0).toUpperCase() + ls.slice(1) + '</option>';
    });
    html += '</select>';

    html += '<label>Submit Button Text</label>';
    html += '<input type="text" id="bs-submitText" value="' + esc(s.submitText) + '" />';

    html += '<label>Success Message</label>';
    html += '<input type="text" id="bs-successMessage" value="' + esc(s.successMessage) + '" />';

    return html;
  }

  function renderMultiStepContent() {
    var s = config.settings;
    var html = '';

    html += '<div class="checkbox-row">';
    html += '<input type="checkbox" id="bs-multiStep"' + (s.multiStep ? ' checked' : '') + ' />';
    html += '<label for="bs-multiStep">Enable Multi-Step</label>';
    html += '</div>';

    html += '<div class="checkbox-row" style="margin-top:6px;' + (s.multiStep ? '' : 'display:none;') + '" id="bs-progressBar-row">';
    html += '<input type="checkbox" id="bs-progressBar"' + (s.progressBar ? ' checked' : '') + ' />';
    html += '<label for="bs-progressBar">Show Progress Bar</label>';
    html += '</div>';

    html += '<div style="' + (s.multiStep ? '' : 'display:none;') + '" id="bs-titlepage-section">';
    html += '<div class="checkbox-row" style="margin-top:6px;">';
    html += '<input type="checkbox" id="bs-titlePageEnabled"' + (s.titlePage.enabled ? ' checked' : '') + ' />';
    html += '<label for="bs-titlePageEnabled">Add Title Page</label>';
    html += '</div>';
    html += '<div id="bs-titlepage-fields" style="' + (s.titlePage.enabled ? '' : 'display:none;') + '">';
    html += '<label>Heading</label><input type="text" id="bs-tpHeading" value="' + esc(s.titlePage.heading) + '" />';
    html += '<label>Description</label><textarea id="bs-tpDescription">' + esc(s.titlePage.description) + '</textarea>';
    html += '<label>Button Text</label><input type="text" id="bs-tpButtonText" value="' + esc(s.titlePage.buttonText) + '" />';
    html += '</div>';
    html += '</div>';

    return html;
  }

  function renderScoringContent() {
    var sc = config.settings.scoring;
    var html = '';

    html += '<div class="checkbox-row">';
    html += '<input type="checkbox" id="bs-scoringEnabled"' + (sc.enabled ? ' checked' : '') + ' />';
    html += '<label for="bs-scoringEnabled">Enable Scoring</label>';
    html += '</div>';

    html += '<div id="bs-scoring-fields" style="' + (sc.enabled ? '' : 'display:none;') + '">';
    html += '<div class="checkbox-row" style="margin-top:6px;"><input type="checkbox" id="bs-showTotal"' + (sc.showTotal ? ' checked' : '') + ' /><label for="bs-showTotal">Show Total to User</label></div>';
    html += '<label>Score Label</label><input type="text" id="bs-totalLabel" value="' + esc(sc.totalLabel) + '" />';
    html += '<label>Send Score As</label><input type="text" id="bs-sendAs" value="' + esc(sc.sendAs) + '" />';
    html += '</div>';

    return html;
  }

  /* ═══════════════════════════════════════════════
     FIELD SETTINGS (SIDEBAR ACCORDIONS)
     ═══════════════════════════════════════════════ */
  function renderFieldSettingsSidebar(f) {
    var html = '';
    var hasOptions = f.type === 'select' || f.type === 'checkbox' || f.type === 'radio';

    // General
    html += renderAccordion('fieldGeneral', 'General', renderFieldGeneralContent(f));

    // Options (only for option types)
    if (hasOptions) {
      html += renderAccordion('fieldOptions', 'Options', renderOptionsEditor(f));
    }

    // Advanced
    html += renderAccordion('fieldAdvanced', 'Advanced', renderFieldAdvancedContent(f));

    // Conditional Logic
    html += renderAccordion('fieldConditions', 'Conditional Logic', renderConditionsContent(f));

    return html;
  }

  function renderFieldGeneralContent(f) {
    var html = '';

    if (f.type === 'divider') {
      html += '<p style="color:#666;font-size:12px;margin:4px 0;">No general settings for dividers.</p>';
      return html;
    }

    if (f.type === 'heading') {
      html += settingField('Heading Text', 'label', f.label, 'text', '');
      return html;
    }

    if (f.type === 'paragraph') {
      html += settingField('Paragraph Text', 'label', f.label, 'textarea', '');
      return html;
    }

    if (f.type === 'hidden') {
      html += settingField('Field Name', 'name', f.name, 'text', '');
      html += settingField('Value', 'defaultValue', f.defaultValue, 'text', '');
      return html;
    }

    // All input types
    html += settingField('Label', 'label', f.label, 'text', '');
    html += settingField('Field Name', 'name', f.name, 'text', '');
    html += settingField('Placeholder', 'placeholder', f.placeholder || '', 'text', '');
    html += settingField('Default Value', 'defaultValue', f.defaultValue || '', 'text', '');
    html += settingCheckbox('Required', 'required', f.required);
    html += settingField('Help Text', 'helpText', f.helpText || '', 'text', '');

    // Number-specific
    if (f.type === 'number') {
      html += settingField('Min', 'min', f.min != null ? f.min : '', 'number', '');
      html += settingField('Max', 'max', f.max != null ? f.max : '', 'number', '');
      html += settingField('Step', 'numStep', f.numStep != null ? f.numStep : '', 'number', '');
    }

    return html;
  }

  function renderFieldAdvancedContent(f) {
    var html = '';

    if (f.type === 'heading' || f.type === 'paragraph' || f.type === 'divider') {
      html += settingField('CSS Class', 'cssClass', f.cssClass || '', 'text', '');
      return html;
    }

    if (f.type === 'hidden') {
      html += settingCheckbox('Custom Field', 'isCustom', f.isCustom);
      html += settingCheckbox('Log Visible', 'logVisible', f.logVisible);
      return html;
    }

    // Width
    html += '<div><label>Width</label>';
    html += '<select data-key="width">';
    ['full', 'half', 'third', 'quarter'].forEach(function (w) {
      html += '<option value="' + w + '"' + (f.width === w ? ' selected' : '') + '>' + w.charAt(0).toUpperCase() + w.slice(1) + '</option>';
    });
    html += '</select></div>';

    // Label style override
    html += '<div><label>Label Style</label>';
    html += '<select data-key="labelStyle">';
    ['inherit', 'above', 'floating', 'hidden'].forEach(function (s) {
      html += '<option value="' + s + '"' + (f.labelStyle === s ? ' selected' : '') + '>' + s.charAt(0).toUpperCase() + s.slice(1) + '</option>';
    });
    html += '</select></div>';

    html += settingField('CSS Class', 'cssClass', f.cssClass || '', 'text', '');
    html += settingCheckbox('Custom Field', 'isCustom', f.isCustom);
    html += settingCheckbox('Log Visible', 'logVisible', f.logVisible);

    // Step assignment (multi-step)
    if (config.settings.multiStep) {
      var maxStep = getMaxStep();
      html += '<div><label>Step</label>';
      html += '<select data-key="step">';
      for (var s = 0; s <= maxStep; s++) {
        html += '<option value="' + s + '"' + ((f.step || 0) === s ? ' selected' : '') + '>Step ' + (s + 1) + '</option>';
      }
      html += '</select></div>';
    }

    return html;
  }

  function renderConditionsContent(f) {
    var hasConditions = f.conditions && f.conditions.length > 0;
    var html = '';

    html += '<div class="ctm-conditions-toggle checkbox-row">';
    html += '<input type="checkbox" class="cond-toggle" data-field-id="' + f.id + '"' + (hasConditions ? ' checked' : '') + ' />';
    html += '<label>Show this field conditionally</label>';
    html += '</div>';

    html += '<div class="ctm-conditions-panel" style="' + (hasConditions ? '' : 'display:none;') + '" data-field-id="' + f.id + '">';

    // Logic selector
    html += '<div class="ctm-conditions-logic">Show when <select class="cond-logic" data-field-id="' + f.id + '">';
    html += '<option value="all"' + ((f.conditionLogic || 'all') === 'all' ? ' selected' : '') + '>all</option>';
    html += '<option value="any"' + (f.conditionLogic === 'any' ? ' selected' : '') + '>any</option>';
    html += '</select> conditions match:</div>';

    // Condition rows
    var conditions = f.conditions || [];
    conditions.forEach(function (cond, idx) {
      html += renderConditionRow(f.id, idx, cond);
    });

    html += '<button type="button" class="button ctm-add-condition" data-field-id="' + f.id + '">+ Add Condition</button>';
    html += '</div>';

    return html;
  }

  /* ═══════════════════════════════════════════════
     SHARED HELPERS
     ═══════════════════════════════════════════════ */
  function settingField(label, key, value, inputType, extraClass) {
    var cls = extraClass ? ' class="' + extraClass + '"' : '';
    var fid = 'sf_' + uid();
    var html = '<div' + cls + '><label for="' + fid + '">' + esc(label) + '</label>';
    if (inputType === 'textarea') {
      html += '<textarea id="' + fid + '" data-key="' + key + '">' + esc(value) + '</textarea>';
    } else {
      html += '<input type="' + inputType + '" id="' + fid + '" data-key="' + key + '" value="' + esc(value) + '" />';
    }
    html += '</div>';
    return html;
  }

  function settingCheckbox(label, key, checked) {
    var fid = 'sf_' + uid();
    return '<div class="checkbox-row"><input type="checkbox" id="' + fid + '" data-key="' + key + '"' + (checked ? ' checked' : '') + ' /><label for="' + fid + '">' + esc(label) + '</label></div>';
  }

  /* ═══════════════════════════════════════════════
     OPTIONS EDITOR (select, checkbox, radio)
     ═══════════════════════════════════════════════ */
  function renderOptionsEditor(f) {
    var opts = f.options || [];
    var showScore = config.settings.scoring && config.settings.scoring.enabled;

    var html = '<div class="ctm-options-editor">';
    html += '<div class="ctm-options-list" data-field-id="' + f.id + '">';

    opts.forEach(function (opt, idx) {
      html += '<div class="ctm-option-row" data-opt-idx="' + idx + '">';
      html += '<span class="opt-drag dashicons dashicons-menu"></span>';
      html += '<input type="text" class="opt-label" placeholder="Label" value="' + esc(opt.label) + '" />';
      html += '<input type="text" class="opt-value" placeholder="Value" value="' + esc(opt.value) + '" />';
      if (showScore) {
        html += '<span class="opt-score-label">Score:</span>';
        html += '<input type="number" class="opt-score" value="' + (opt.score || 0) + '" />';
      }
      html += '<button type="button" class="opt-remove" title="Remove"><span class="dashicons dashicons-no-alt"></span></button>';
      html += '</div>';
    });

    html += '</div>';
    html += '<button type="button" class="button ctm-add-option" data-field-id="' + f.id + '">+ Add Option</button>';
    html += '</div>';

    return html;
  }

  /* ═══════════════════════════════════════════════
     CONDITIONS — SINGLE ROW
     ═══════════════════════════════════════════════ */
  function renderConditionRow(fieldId, idx, cond) {
    // Build field dropdown (all other input-type fields)
    var otherFields = config.fields.filter(function (f) {
      return f.id !== fieldId && ['heading', 'paragraph', 'divider'].indexOf(f.type) === -1;
    });

    var html = '<div class="ctm-condition-row" data-cond-idx="' + idx + '">';

    // Field selector
    html += '<select class="cond-field">';
    html += '<option value="">\u2014 Field \u2014</option>';
    otherFields.forEach(function (f) {
      html += '<option value="' + f.id + '"' + (cond.field === f.id ? ' selected' : '') + '>' + esc(f.label || f.name) + '</option>';
    });
    html += '</select>';

    // Operator
    html += '<select class="cond-operator">';
    OPERATORS.forEach(function (op) {
      html += '<option value="' + op.value + '"' + (cond.operator === op.value ? ' selected' : '') + '>' + esc(op.label) + '</option>';
    });
    html += '</select>';

    // Value (hidden for is_empty / is_not_empty)
    var hideVal = cond.operator === 'is_empty' || cond.operator === 'is_not_empty';
    html += '<input type="text" class="cond-value" placeholder="Value" value="' + esc(cond.value || '') + '" style="' + (hideVal ? 'display:none;' : '') + '" />';

    html += '<button type="button" class="cond-remove" title="Remove"><span class="dashicons dashicons-no-alt"></span></button>';
    html += '</div>';

    return html;
  }

  /* ═══════════════════════════════════════════════
     MULTI-STEP CONTROLS
     ═══════════════════════════════════════════════ */
  function getMaxStep() {
    var max = 0;
    config.fields.forEach(function (f) {
      if ((f.step || 0) > max) max = f.step;
    });
    return max;
  }

  function getStepCount() {
    return getMaxStep() + 1;
  }

  function initMultiStepControls() {
    // Step tab clicks
    $(document).on('click', '.ctm-step-tab', function () {
      activeStep = parseInt($(this).data('step'), 10);
      renderMultiStepControls();
      renderFieldList();
    });

    // Add step
    $(document).on('click', '#ctm-add-step', function () {
      activeStep = getStepCount();
      // We don't need to do anything special - just switching to a new step
      renderMultiStepControls();
      renderFieldList();
    });

    // Remove last step
    $(document).on('click', '#ctm-remove-step', function () {
      var count = getStepCount();
      if (count <= 1) return;
      var lastStep = count - 1;
      // Move fields from last step to previous
      config.fields.forEach(function (f) {
        if ((f.step || 0) === lastStep) {
          f.step = lastStep - 1;
        }
      });
      if (activeStep >= lastStep) activeStep = lastStep - 1;
      renderMultiStepControls();
      renderFieldList();
      syncConfig();
    });
  }

  function renderMultiStepControls() {
    var $wrap = $('#ctm-multistep-controls');
    if (!config.settings.multiStep) {
      $wrap.hide();
      return;
    }
    $wrap.show();

    var count = getStepCount();
    var html = '<div class="ctm-step-tabs">';
    for (var i = 0; i < count; i++) {
      html += '<button type="button" class="ctm-step-tab' + (i === activeStep ? ' active' : '') + '" data-step="' + i + '">Step ' + (i + 1) + '</button>';
    }
    html += '</div>';
    html += '<div class="ctm-step-actions">';
    html += '<button type="button" class="button" id="ctm-add-step">+ Add Step</button>';
    if (count > 1) {
      html += '<button type="button" class="button" id="ctm-remove-step">Remove Last Step</button>';
    }
    html += '</div>';

    $wrap.html(html);
  }

  /* ═══════════════════════════════════════════════
     EVENTS (delegated)
     ═══════════════════════════════════════════════ */
  function initSettingsEvents() {

    // ── Click field row to select/deselect ──
    $(document).on('click', '.ctm-field-row .ctm-field-header', function (e) {
      // Don't select if clicking action buttons or drag handle
      if ($(e.target).closest('.ctm-field-actions').length) return;
      if ($(e.target).closest('.ctm-drag-handle').length) return;

      var id = $(this).closest('.ctm-field-row').data('field-id');
      if (selectedFieldId === id) {
        selectedFieldId = null;
      } else {
        selectedFieldId = id;
      }

      // Update visual selection
      $canvas.find('.ctm-field-row').removeClass('selected');
      if (selectedFieldId) {
        $canvas.find('[data-field-id="' + selectedFieldId + '"]').addClass('selected');
      }

      renderSidebar();
    });

    // ── Delete field ──
    $(document).on('click', '.ctm-btn-delete', function (e) {
      e.stopPropagation();
      var id = $(this).closest('.ctm-field-row').data('field-id');
      removeField(id);
    });

    // ── Duplicate field ──
    $(document).on('click', '.ctm-btn-dup', function (e) {
      e.stopPropagation();
      var id = $(this).closest('.ctm-field-row').data('field-id');
      duplicateField(id);
    });

    // ── Accordion toggle ──
    $(document).on('click', '#ctm-builder-sidebar .ctm-accordion-header', function () {
      var $acc = $(this).closest('.ctm-accordion');
      var key = $acc.data('accordion');
      $acc.toggleClass('open');
      accordionState[key] = $acc.hasClass('open');
    });

    // ── Field settings input changes (sidebar) ──
    $(document).on('input change', '#ctm-builder-sidebar .ctm-sidebar-field [data-key]', function () {
      if (!selectedFieldId) return;
      var key = $(this).data('key');
      var val;

      if ($(this).is(':checkbox')) {
        val = $(this).prop('checked');
      } else if ($(this).attr('type') === 'number') {
        val = $(this).val() !== '' ? parseFloat($(this).val()) : null;
      } else {
        val = $(this).val();
      }

      // Convert step to number
      if (key === 'step') val = parseInt(val, 10);

      updateField(selectedFieldId, key, val);

      // Update header display in canvas
      if (key === 'label' || key === 'name' || key === 'width' || key === 'required') {
        var f = findField(selectedFieldId);
        var $row = $canvas.find('[data-field-id="' + selectedFieldId + '"]');
        if (f && $row.length) {
          $row.find('.ctm-field-label-text').text(f.label || 'Untitled');
          $row.find('.ctm-field-name-text').text(f.name ? '(' + f.name + ')' : '');
          // Rebuild badges
          renderBadges($row, f);
        }
        // Update sidebar field header text
        if (key === 'label' && f) {
          $sidebar.find('.ctm-sidebar-field-header').html('Field: \u201c' + esc(f.label || f.type) + '\u201d');
        }
      }

      // Re-render field list if step changed (field moves to different tab)
      if (key === 'step') {
        renderFieldList();
      }
    });

    // ── Options editor: label/value/score changes ──
    $(document).on('input', '.ctm-option-row .opt-label, .ctm-option-row .opt-value, .ctm-option-row .opt-score', function () {
      var $list = $(this).closest('.ctm-options-list');
      var fieldId = $list.data('field-id');
      var f = findField(fieldId);
      if (!f || !f.options) return;

      var $row = $(this).closest('.ctm-option-row');
      var idx = $row.data('opt-idx');

      if ($(this).hasClass('opt-label')) f.options[idx].label = $(this).val();
      if ($(this).hasClass('opt-value')) f.options[idx].value = $(this).val();
      if ($(this).hasClass('opt-score')) f.options[idx].score = parseFloat($(this).val()) || 0;

      syncConfig();
    });

    // ── Add option ──
    $(document).on('click', '.ctm-add-option', function () {
      var fieldId = $(this).data('field-id');
      var f = findField(fieldId);
      if (!f) return;
      if (!f.options) f.options = [];
      var n = f.options.length + 1;
      f.options.push({ label: 'Option ' + n, value: 'opt' + n, score: 0 });
      renderSidebar();
      syncConfig();
    });

    // ── Remove option ──
    $(document).on('click', '.opt-remove', function () {
      var $list = $(this).closest('.ctm-options-list');
      var fieldId = $list.data('field-id');
      var f = findField(fieldId);
      if (!f || !f.options) return;

      var idx = $(this).closest('.ctm-option-row').data('opt-idx');
      f.options.splice(idx, 1);
      renderSidebar();
      syncConfig();
    });

    // ── Condition toggle ──
    $(document).on('change', '.cond-toggle', function () {
      var fieldId = $(this).data('field-id');
      var f = findField(fieldId);
      if (!f) return;

      if ($(this).prop('checked')) {
        if (!f.conditions || f.conditions.length === 0) {
          f.conditions = [{ field: '', operator: 'equals', value: '' }];
        }
      } else {
        f.conditions = [];
      }
      renderSidebar();
      syncConfig();
      renderBadges($canvas.find('[data-field-id="' + fieldId + '"]'), f);
    });

    // ── Condition logic change ──
    $(document).on('change', '.cond-logic', function () {
      var fieldId = $(this).data('field-id');
      updateField(fieldId, 'conditionLogic', $(this).val());
    });

    // ── Condition row changes ──
    $(document).on('change input', '.ctm-condition-row select, .ctm-condition-row input', function () {
      var $panel = $(this).closest('.ctm-conditions-panel');
      var fieldId = $panel.data('field-id');
      var f = findField(fieldId);
      if (!f) return;

      // Read all conditions from DOM
      var conds = [];
      $panel.find('.ctm-condition-row').each(function () {
        var c = {
          field: $(this).find('.cond-field').val(),
          operator: $(this).find('.cond-operator').val(),
          value: $(this).find('.cond-value').val()
        };
        conds.push(c);
      });
      f.conditions = conds;
      syncConfig();

      // Toggle value visibility based on operator
      if ($(this).hasClass('cond-operator')) {
        var op = $(this).val();
        var $val = $(this).closest('.ctm-condition-row').find('.cond-value');
        $val.toggle(op !== 'is_empty' && op !== 'is_not_empty');
      }
    });

    // ── Add condition ──
    $(document).on('click', '.ctm-add-condition', function () {
      var fieldId = $(this).data('field-id');
      var f = findField(fieldId);
      if (!f) return;
      if (!f.conditions) f.conditions = [];
      f.conditions.push({ field: '', operator: 'equals', value: '' });
      renderSidebar();
      syncConfig();
    });

    // ── Remove condition ──
    $(document).on('click', '.cond-remove', function () {
      var $panel = $(this).closest('.ctm-conditions-panel');
      var fieldId = $panel.data('field-id');
      var f = findField(fieldId);
      if (!f) return;

      var idx = $(this).closest('.ctm-condition-row').data('cond-idx');
      f.conditions.splice(idx, 1);
      renderSidebar();
      syncConfig();
      renderBadges($canvas.find('[data-field-id="' + fieldId + '"]'), f);
    });

    // ── Form settings changes (sidebar, bs-* IDs) ──
    $(document).on('change input', '#ctm-builder-sidebar input[id^="bs-"], #ctm-builder-sidebar select[id^="bs-"], #ctm-builder-sidebar textarea[id^="bs-"]', function () {
      var id = $(this).attr('id');
      if (!id || !id.startsWith('bs-')) return;
      var key = id.substring(3);
      var val;

      if ($(this).is(':checkbox')) {
        val = $(this).prop('checked');
      } else {
        val = $(this).val();
      }

      // Map settings
      switch (key) {
        case 'labelStyle':
          config.settings.labelStyle = val;
          break;
        case 'submitText':
          config.settings.submitText = val;
          break;
        case 'successMessage':
          config.settings.successMessage = val;
          break;
        case 'multiStep':
          config.settings.multiStep = val;
          renderMultiStepControls();
          renderFieldList();
          renderSidebar();
          break;
        case 'progressBar':
          config.settings.progressBar = val;
          break;
        case 'titlePageEnabled':
          config.settings.titlePage.enabled = val;
          $sidebar.find('#bs-titlepage-fields').toggle(val);
          break;
        case 'tpHeading':
          config.settings.titlePage.heading = val;
          break;
        case 'tpDescription':
          config.settings.titlePage.description = val;
          break;
        case 'tpButtonText':
          config.settings.titlePage.buttonText = val;
          break;
        case 'scoringEnabled':
          config.settings.scoring.enabled = val;
          // Re-render to show/hide score columns in options editor
          renderSidebar();
          break;
        case 'showTotal':
          config.settings.scoring.showTotal = val;
          break;
        case 'totalLabel':
          config.settings.scoring.totalLabel = val;
          break;
        case 'sendAs':
          config.settings.scoring.sendAs = val;
          break;
      }

      syncConfig();
    });
  }

  function renderBadges($row, f) {
    var badges = '';
    if (f.width && f.width !== 'full') {
      badges += '<span class="ctm-badge ctm-badge-width">' + esc(f.width) + '</span>';
    }
    if (f.required) {
      badges += '<span class="ctm-badge ctm-badge-required">*</span>';
    }
    if (config.settings.multiStep && typeof f.step !== 'undefined') {
      badges += '<span class="ctm-badge ctm-badge-step">Step ' + (f.step + 1) + '</span>';
    }
    if (f.conditions && f.conditions.length > 0) {
      badges += '<span class="ctm-badge ctm-badge-conditions">Conditional</span>';
    }
    if (!f.isCustom) {
      badges += '<span class="ctm-badge ctm-badge-custom">Core</span>';
    }
    $row.find('.ctm-field-badges').html(badges);
  }

  function initOptionsSortable($container) {
    $container.find('.ctm-options-list').sortable({
      handle: '.opt-drag',
      items: '.ctm-option-row',
      update: function () {
        var fieldId = $(this).data('field-id');
        var f = findField(fieldId);
        if (!f || !f.options) return;

        var newOpts = [];
        $(this).children('.ctm-option-row').each(function () {
          var idx = $(this).data('opt-idx');
          if (f.options[idx]) newOpts.push(f.options[idx]);
        });
        f.options = newOpts;

        // Re-index
        $(this).children('.ctm-option-row').each(function (i) {
          $(this).attr('data-opt-idx', i).data('opt-idx', i);
        });

        syncConfig();
      }
    });
  }

  /* ═══════════════════════════════════════════════
     SYNC CONFIG → HIDDEN INPUT + TRIGGER PREVIEW
     ═══════════════════════════════════════════════ */
  function syncConfig() {
    $configInput.val(JSON.stringify(config));
    triggerPreview();
  }

  function triggerPreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(doPreview, 600);
  }

  function doPreview() {
    if (!$previewFrame || !$previewFrame.length) return;

    $previewFrame.addClass('loading');

    var data = {
      action: 'ctm_builder_preview',
      config: JSON.stringify(config)
    };
    data[CTM_BUILDER.nonceName] = CTM_BUILDER.nonce;

    $.post(CTM_BUILDER.ajaxUrl, data, function (res) {
      $previewFrame.removeClass('loading');
      if (res && res.success && res.data && res.data.html) {
        $previewFrame.html(res.data.html);
      } else {
        $previewFrame.html('<div class="ctm-preview-empty">Preview will appear here</div>');
      }
    }).fail(function () {
      $previewFrame.removeClass('loading');
    });
  }

  /* ═══════════════════════════════════════════════
     REACTOR TAB LEGACY (moved from inline script)
     ═══════════════════════════════════════════════ */
  function initReactorTabLegacy() {
    var genBtn = document.getElementById('ctm-generate');
    var sel = document.getElementById('ctm_reactor_id_reactor');
    var ta = document.getElementById('ctm_form_html');
    var copyBtn = document.getElementById('ctm-copy-sc');
    var scfld = document.getElementById('ctm-shortcode-field');

    if (genBtn) {
      genBtn.addEventListener('click', function () {
        var id = sel ? sel.value : '';
        if (!id) { alert('Please choose a reactor first.'); return; }
        var floatingLabels = document.getElementById('ctm_floating_labels');
        floatingLabels = floatingLabels && floatingLabels.checked ? '1' : '0';

        var fd = new FormData();
        fd.append('action', 'anchor_ctm_generate');
        fd.append(CTM_BUILDER.nonceName, CTM_BUILDER.nonce);
        fd.append('reactor_id', id);
        fd.append('floating_labels', floatingLabels);

        fetch(CTM_BUILDER.ajaxUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.success) {
              ta.value = data.data.html || '';
            } else {
              alert((data && data.data) || 'Failed to generate form.');
            }
          });
      });
    }

    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        scfld.select();
        scfld.setSelectionRange(0, 99999);
        document.execCommand('copy');
        copyBtn.textContent = 'Copied';
        setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1200);
      });
    }

    // Analytics override toggle
    var overrideChk = document.getElementById('ctm_analytics_override');
    var analyticsFields = document.getElementById('ctm-analytics-fields');
    if (overrideChk) {
      overrideChk.addEventListener('change', function () {
        analyticsFields.style.display = overrideChk.checked ? '' : 'none';
      });
    }

    // Reactor-tab multi-step + title page toggles
    var msChk = document.getElementById('ctm_multi_step_reactor');
    var tpChk = document.getElementById('ctm_title_page_reactor');
    var tpLabel = document.getElementById('ctm-title-page-label-reactor');
    var tpFields = document.getElementById('ctm-title-page-fields-reactor');
    var msInstructions = document.getElementById('ctm-ms-instructions');

    function toggleTitleFields() {
      if (!msChk) return;
      if (tpLabel) tpLabel.style.display = msChk.checked ? '' : 'none';
      if (tpFields) tpFields.style.display = (msChk.checked && tpChk && tpChk.checked) ? '' : 'none';
      if (msInstructions) msInstructions.style.display = msChk.checked ? '' : 'none';
    }
    if (msChk) msChk.addEventListener('change', toggleTitleFields);
    if (tpChk) tpChk.addEventListener('change', toggleTitleFields);
    toggleTitleFields();
  }

})(jQuery);
