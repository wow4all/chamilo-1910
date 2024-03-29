<script>
$(document).ready(function() {
    $.ajax({
        url:'{{ web_admin_ajax_url }}?a=version',
        success:function(version){
            $(".admin-block-version").html(version);
        }
    });

{% if _u.is_admin %}
    (function(){
        $('.edit-block a').on('click', function(e) {
            e.preventDefault();

            var $self = $(this);

            var extraContent = $.ajax('{{ _p.web_ajax }}admin.ajax.php', {
                type: 'post',
                data: {
                    a: 'get_extra_content',
                    block: $self.data('id')
                }
            });

            $.when(extraContent).done(function(content) {
                FCKeditorAPI.GetInstance('extra_content').SetData(content);
                $('#extra-block').val($self.data('id'));
                $('#modal-extra-title').text($self.data('label'));

                $('#modal-extra').modal('show');
            });
        });

        $('#btn-block-editor-save').on('click', function(e) {
            e.preventDefault();

            var formParams = $.param({
                a: 'save_block_extra',
                extra_content: FCKeditorAPI.GetInstance('extra_content').GetHTML(),
                block:  $('#extra-block').val()
            });

            var save = $.ajax('{{ _p.web_ajax }}admin.ajax.php', {
                type: 'post',
                data: formParams
            });

            $.when(save).done(function() {
                window.location.reload();
            });
        });
    })();
{% endif %}
});
</script>

<div id="settings">
    <div class="row">
    {% for block_item in blocks %}
        <div id="tabs-{{ loop.index }}" class="span6">
            <div class="well_border {{ block_item.class }}">
                {% if block_item.editable and _u.is_admin %}
                    <div class="pull-right edit-block" id="edit-{{ block_item.class }}">
                        <a href="#" data-label="{{ block_item.label }}" data-id="{{ block_item.class }}">
                            <img src="{{ _p.web_img }}icons/22/edit.png" alt="{{ 'Edit' | get_lang }}" title="{{ 'Edit' | get_lang }}">
                        </a>
                    </div>
                {% endif %}
                <h4>{{ block_item.icon }} {{ block_item.label }}</h4>
                <div style="list-style-type:none">
                    {{ block_item.search_form }}
                </div>
                {% if block_item.items is not null %}
                    <ul>
    		    	{% for url in block_item.items %}
    		    		<li>
                            <a href="{{ url.url }}">
                                {{ url.label }}
                            </a>
                        </li>
    				{% endfor %}
                    </ul>
                {% endif %}

                {% if block_item.extra is not null %}
                    <div>
                    {{ block_item.extra }}
                    </div>
                {% endif %}

                {% if block_item.extraContent %}
                    <div>{{ block_item.extraContent }}</div>
                {% endif %}
            </div>
        </div>
    {% endfor %}
    </div>
</div>

{% if _u.is_admin %}
<div id="modal-extra" class="modal hide fade" tabindex="-1" role="dialog" aria-labelledby="modal-extra-title" aria-hidden="true">
    <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h3 id="modal-extra-title">{{ 'Blocks' | get_lang }}</h3>
    </div>
    <div class="modal-body">
        {{ extraDataForm }}
    </div>
</div>
{% endif %}
