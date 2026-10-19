(function($){
  $(function(){
    var selection=[];
    function updateSelection(){
      $('#wpa-selected-count').text(selection.length+' selected');
      $('#wpa-compress-images').prop('disabled',!selection.length);
      $('#wpa-selected-images').empty();
      if(!selection.length){ $('#wpa-selected-images').html('<p>Select images above to prepare a batch.</p>'); return; }
      selection.forEach(function(item){ $('#wpa-selected-images').append($('<div class="wpa-image-chip"/>').append($('<img/>',{src:item.url,alt:''})).append($('<span/>').text(item.filename))); });
    }
    function toggleTile(tile){
      var id=Number(tile.data('id')), index=selection.findIndex(function(item){return Number(item.id)===id;});
      if(index>-1){ selection.splice(index,1); tile.removeClass('is-selected').attr('aria-pressed','false'); }
      else { selection.push({id:id,url:tile.data('url'),filename:tile.data('filename')}); tile.addClass('is-selected').attr('aria-pressed','true'); }
      updateSelection();
    }
    function markSelectedTiles(){
      $('#wpa-media-grid .wpa-media-tile').each(function(){
        var tile=$(this), found=selection.some(function(item){return Number(item.id)===Number(tile.data('id'));});
        tile.toggleClass('is-selected',found).attr('aria-pressed',found?'true':'false');
      });
      var tiles=$('#wpa-media-grid .wpa-media-tile'), all=tiles.length&&tiles.toArray().every(function(el){return $(el).hasClass('is-selected');});
      $('#wpa-select-visible').text(all?'Clear visible selection':'Select all on this page');
    }
    function loadPage(page){
      $('#wpa-media-grid').addClass('is-loading');
      $.post(wpaCompressor.ajaxUrl,{action:'wpa_load_media_images',nonce:wpaCompressor.nonce,page:page,search:$('#wpa-media-search').val(),sort:$('#wpa-media-sort').val(),status:$('#wpa-media-status').val()}).done(function(response){
        if(!response.success){ return; }
        $('#wpa-media-grid').html(response.data.html); $('#wpa-media-pagination').html(response.data.pagination); markSelectedTiles();
      }).always(function(){$('#wpa-media-grid').removeClass('is-loading');});
    }
    $('#wpa-media-grid').on('click','.wpa-media-tile',function(){ toggleTile($(this)); });
    $('#wpa-select-visible').on('click',function(){
      var tiles=$('#wpa-media-grid .wpa-media-tile'), all=tiles.length&&tiles.toArray().every(function(el){return $(el).hasClass('is-selected');});
      tiles.each(function(){var tile=$(this), id=Number(tile.data('id')), index=selection.findIndex(function(item){return Number(item.id)===id;}); if(all&&index>-1){selection.splice(index,1);}else if(!all&&index<0){selection.push({id:id,url:tile.data('url'),filename:tile.data('filename')});}}); markSelectedTiles(); updateSelection();
    });
    $('#wpa-media-pagination').on('click','.wpa-page-button',function(){
      loadPage(Number($(this).data('page')));
    });
    var searchTimer; $('#wpa-media-search').on('input',function(){clearTimeout(searchTimer);searchTimer=setTimeout(function(){loadPage(1);},280);}); $('#wpa-media-sort,#wpa-media-status').on('change',function(){loadPage(1);});
    $('#wpa-quality').on('input',function(){$('#wpa-quality-value').text(this.value);});
    $('#wpa-compress-images').on('click',function(){
      var button=$(this), results=$('#wpa-compression-results'); button.prop('disabled',true).text('Compressing…'); results.html('<p>Creating optimized copies…</p>');
      $.post(wpaCompressor.ajaxUrl,{action:'wpa_compress_images',nonce:wpaCompressor.nonce,ids:selection.map(function(x){return x.id;}),quality:$('#wpa-quality').val()}).done(function(response){
        if(!response.success){results.html($('<p class="wpa-error"/>').text(response.data.message));return;}
        var list=$('<ul/>'); response.data.results.forEach(function(r){if(r.error){list.append($('<li class="wpa-error"/>').text(r.error));}else{list.append($('<li/>').append($('<a/>',{href:r.url,target:'_blank',text:'Optimized WebP'})).append(document.createTextNode(' — '+r.before+' → '+r.after)));}}); results.empty().append('<h3>Completed</h3>').append(list);
      }).fail(function(){results.html('<p class="wpa-error">Compression request failed. Please try again.</p>');}).always(function(){button.prop('disabled',!selection.length).text('Create optimized WebP copies');});
    });
    updateSelection();
  });
})(jQuery);

