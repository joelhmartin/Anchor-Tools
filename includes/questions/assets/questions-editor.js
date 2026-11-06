/* Shared question-row editor (Events attendee questions, Checkout Questions).
 * Binds every .anchor-questions-editor wrapper; the field-name prefix comes from
 * its data-prefix. Adds clone the template and fill __INDEX__; removes renumber so
 * the posted array has no gaps. */
(function($){
  function escapeRe(s){ return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  function init(wrap){
    if(wrap.data('qeBound')){ return; }
    wrap.data('qeBound', true);
    var prefix = String(wrap.data('prefix') || '');
    var rows = wrap.find('.anchor-questions-rows');
    var tpl = wrap.find('.anchor-question-template');
    var renumber = new RegExp(escapeRe(prefix) + '\\[\\d+\\]');

    // "Choices" only means anything for a select.
    function toggleChoices(row){
      var qtype = row.find('.anchor-question-type').val();
      var isSelect = qtype === 'select' || qtype === 'multiselect';
      row.find('textarea[name*="[options]"]').prop('disabled', !isSelect).toggle(isSelect);
    }

    wrap.on('click', '.anchor-question-add', function(e){
      e.preventDefault();
      var i = rows.find('.anchor-question-row').length;
      rows.append(tpl.html().replace(/__INDEX__/g, i));
      toggleChoices(rows.find('.anchor-question-row').last());
    });

    wrap.on('click', '.anchor-question-remove', function(e){
      e.preventDefault();
      $(this).closest('.anchor-question-row').remove();
      rows.find('.anchor-question-row').each(function(i){
        $(this).find('input, select, textarea').each(function(){
          var n = $(this).attr('name');
          if(n){ $(this).attr('name', n.replace(renumber, prefix + '[' + i + ']')); }
        });
      });
    });

    wrap.on('change', '.anchor-question-type', function(){ toggleChoices($(this).closest('.anchor-question-row')); });
    rows.find('.anchor-question-row').each(function(){ toggleChoices($(this)); });
  }

  $(function(){ $('.anchor-questions-editor').each(function(){ init($(this)); }); });
})(jQuery);
