(function( $ ){
    var methods = {
        init : function(params) {
            $('textarea', $(this)).addTinymce();
            $('input.date-picker', $(this)).datepicker($.datepicker.regional['fr']);
            $('a[data-confirm]', $(this)).addConfirm();
            $('.mail-extra', $(this)).addMailExtra();
            $('.preview', $(this)).addMailExtra({preview: true});
            $('select[multiple="multiple"]:visible', $(this)).select2();
            $('.collection').collection({
                allow_up : false,
                allow_down : false,
                add: '<a href="#"><i class="far fa-plus-square"></i></a>',
                remove: '<a href="#"><i class="far fa-minus-square"></i></a>',
                drag_drop: false,
                after_add: function (collection, element) {
                    $(element).initCommon();
                    return true;
                },
            });
            $('.toggle-producer', $(this)).on('click', function (event) {
                event.preventDefault();
                var $i = $('i', $(this)).toggleClass('fa-arrow-up fa-arrow-down');
                $('tr[data-producer-id="' + $i.data('producer') + '"]').toggle();
            });
            $('a', $(this)).initCommon('ajax');
            $('form', $(this)).initCommon('ajax');
            $(this, $(this)).initCommon('addTotals');
            $("input[type=file]", $(this)).change(function () {
                $(this).next(".custom-file-label").attr('data-content', this.files[0].name);
                $(this).next(".custom-file-label").text(this.files[0].name);
            });
            if(null == window.history.state) {
                window.history.replaceState({url: window.location.href}, document.title, window.location.href)
            }
            window.onpopstate = function(event) {
                if (null !== event.state) {
                    $.ajax({
                        url : event.state.url,
                        method: 'get',
                        dataType: 'json',
                        processData: false,
                        contentType: false,
                        success: function(data) {
                            document.title = data.header;
                            $.refreshFromAjax(data);
                        }
                    });
                } else {
                    window.location.href = window.location.href;
                }
            };

            return this;
        },
        addTotals : function() {
            var $context = $(this)
            $('.total-product-id', $(this)).each(function () {
                $context.initCommon('addTotal', '[data-product-id="' + $(this).data('product-id') + '"]', $(this));
            });
            $('.total-producer-id', $(this)).each(function () {
                $context.initCommon('addTotal', '[data-producer-id="' + $(this).data('producer-id') + '"]', $(this));
            });
            $(this).initCommon('addTotal', '[data-product-id]', $('.total', $(this)));

            return this;
        },
        addTotal : function(selector, $target) {
            var total = 0;
            $('span' + selector, $(this)).each(function () {
                total += $(this).data('price') * parseInt($(this).html());
            });
            $('input' + selector, $(this)).each(function () {
                total += $(this).data('price') * parseInt($(this).val());
            });
            $target.html((Math.round(total * 100) / 100).toFixed(2));

            return this;
        },
        ajax : function() {
            var url = $(this).attr('href') || $(this).data('url');
            if ($(this).is('form')) {
                url = $(this).attr('action') || window.location.hash;
            }
            if (undefined == $._data($(this)[0], "events") && url != '#' && url) {
                $(this).data('url', url);
                $(this).on($(this).is('form') ? 'submit' : 'click', function(event) {
                    event.preventDefault();
                    $.ajax({
                        url : $(this).data('url'),
                        method: $(this).is('form') ? 'post' : 'get',
                        data: $(this).is('form') ? new FormData($(this)[0]) : null,
                        dataType: 'json',
                        processData: false,
                        contentType: false,
                        success: function(data) {
                            document.title = data.header;
                            window.history.pushState({url: data.url}, data.header, data.url);
                            $.refreshFromAjax(data);
                        }
                    });
                });
            }
        },
    };
    $.fn.initCommon = function(methodOrOptions) {
        var currentArguments = arguments;
        var result = [];
        this.each(function() {
            if ( methods[methodOrOptions] ) {
                result.push(methods[ methodOrOptions ].apply( this, Array.prototype.slice.call( currentArguments, 1 )));
            } else if ( typeof methodOrOptions === 'object' || ! methodOrOptions ) {
                result.push(methods.init.apply( this, currentArguments ));
            } else {
                $.error( 'Method ' +  methodOrOptions + ' does not exist on jQuery.slot' );
            }
        });

        return this.length == 1 && result.length == 1 ? result[0] : result;
    };
})( jQuery );
