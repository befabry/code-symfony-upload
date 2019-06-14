Dropzone.autoDiscover = false;

$(document).ready(function () {
    var referenceList = new ReferenceList($('.js-reference-list'));

    initializeDropzone(referenceList);

    var $locationSelect = $('.js-article-form-location');
    var $specificLocationTarget = $('.js-specific-location-target');

    $locationSelect.on('change', function (e) {
        $.ajax({
            url: $locationSelect.data('specific-location-url'),
            data: {
                location: $locationSelect.val()
            },
            success: function (html) {
                if (!html) {
                    $specificLocationTarget.find('select').remove();
                    $specificLocationTarget.addClass('d-none');

                    return;
                }

                // Replace the current field and show
                $specificLocationTarget
                    .html(html)
                    .removeClass('d-none')
            }
        });
    });
});

/**
 * @param {ReferenceList} referenceList
 */
function initializeDropzone(referenceList) {
    var formElement = document.querySelector('.js-reference-dropzone');
    if (!formElement) {
        return;
    }

    var dropzone = new Dropzone(formElement, {
        paramName: 'reference',
        init: function () {
            this.on('success', function (file, data) {
                referenceList.addReference(data);
            });

            this.on('error', function (file, data) {
                if (data.detail) {
                    this.emit('error', file, data.detail);
                }
            });
        },
    });
}

// todo - use Webpack Encore so ES6 syntax is transpiled to ES5
class ReferenceList {
    constructor($element) {
        this.$element = $element;
        this.references = [];
        this.render();

        this.$element.on('click', '.js-reference-delete', (event) => {
            this.handleReferenceDelete(event);
        });

        $.ajax({
            url: this.$element.data('url')
        }).then(data => {
            this.references = data;
            this.render();
        })
    }

    addReference(reference) {
        this.references.push(reference);
        this.render();
    }

    handleReferenceDelete(event){
        const $li = $(event.currentTarget).closest('.list-group-item');
        const id = $li.data('id');
        $li.addClass('disabled');

        console.log('test');

        $.ajax({
            url: '/admin/article/references/'+id,
            method: 'DELETE'
        }).then(() => {
            this.references = this.references.filter(reference => {
                return reference.id !== id;
            });
            this.render();
        });
    }

    render() {
        const itemsHtml = this.references.map(reference => {
            return `
<li class="list-group-item d-flex justify-content-between align-items-center" data-id="${reference.id}">
    ${reference.originalFilename}
    <span>
        <a href="/admin/article/references/${reference.id}/download" class="btn btn-link btn-sm" style="vertical-align: middle;"><span class="fa fa-download"></span></a>
        <button class="js-reference-delete btn btn-link btn-sm"><span class="fa fa-trash"></span></button>
    </span>
</li>
`
        });
        this.$element.html(itemsHtml.join(''));
    }
}
