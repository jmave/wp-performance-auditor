(function($){
  $(function(){
    $('#wpa-audit-form, #wpa-bulk-form').on('submit',function(){
      var form=$(this), button=form.find('input[type="submit"],button[type="submit"]');
      button.prop('disabled',true).val('Running audit…').text('Running audit…');
      form.find('.wpa-audit-progress').prop('hidden',false);
    });
  });
})(jQuery);

