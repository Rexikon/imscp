
<script>
    $(function () {
        $('#subdomain_ips').multiSelect({
            selectableHeader: '<div class="ms-header">'+imscp_i18n.core.available+'</div>',
            selectionHeader: '<div class="ms-header">'+imscp_i18n.core.assigned+'</div>'
        });

        <!-- BDP: shared_mount_point_option_js -->
        $("input[name='shared_mount_point']").on('change', function () {
            if ($("#shared_mount_point_no").is(':checked')) {
                $("#shared_mount_point_domain").hide();
            } else {
                $("#shared_mount_point_domain").show();
            }
        }).trigger('change');
        <!-- EDP: shared_mount_point_option_js -->

        $("input[name='url_forwarding']").on('change', function () {
            if ($("#url_forwarding_no").is(':checked')) {
                $("#tr_url_forwarding_data, #tr_type_forwarding_data").hide();
            } else {
                $("#tr_url_forwarding_data, #tr_type_forwarding_data").show();
            }
        }).trigger('change');

        $("input[name='forward_type']").on('change', function () {
            if ($("#forward_type_proxy").is(':checked')) {
                $(".checkbox").show();
            } else {
                $(".checkbox").hide();
            }
        }).trigger('change');
    });
</script>
<form name="add_subdomain_frm" method="post" action="subdomain_add.php">
    <table class="firstColFixed">
        <thead>
        <tr>
            <th colspan="2">{TR_SUBDOMAIN}</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><label for="subdomain_name">{TR_SUBDOMAIN_NAME}</label></td>
            <td>
                <span class="bold">www.</span>
                <input type="text" name="subdomain_name" id="subdomain_name" value="{SUBDOMAIN_NAME}">
                <strong>.</strong>
                <label>
                    <select name="domain_name">
                        <!-- BDP: parent_domain -->
                        <option value="{DOMAIN_NAME}"{DOMAIN_NAME_SELECTED}>{DOMAIN_NAME_UNICODE}</option>
                        <!-- EDP: parent_domain -->
                    </select>
                </label>
            </td>
        </tr>
        <tr>
            <td><label for="subdomain_ips">{TR_SUBDOMAIN_IPS}</label></td>
            <td>
                <select id="subdomain_ips" name="subdomain_ips[]" multiple>
                    <!-- BDP: ip_entry -->
                    <option value="{IP_VALUE}"{IP_SELECTED}>{IP_NUM}</option>
                    <!-- EDP: ip_entry -->
                </select>
            </td>
        </tr>
        <!-- BDP: shared_mount_point_option -->
        <tr>
            <td>{TR_SHARED_MOUNT_POINT} <span class="icon i_help" title="{TR_SHARED_MOUNT_POINT_TOOLTIP}"></span></td>
            <td>
                <div class="radio">
                    <input type="radio" name="shared_mount_point" id="shared_mount_point_yes"
                           value="yes"{SHARED_MOUNT_POINT_YES}>
                    <label for="shared_mount_point_yes">{TR_YES}</label>
                    <input type="radio" name="shared_mount_point" id="shared_mount_point_no"
                           value="no"{SHARED_MOUNT_POINT_NO}>
                    <label for="shared_mount_point_no">{TR_NO}</label>
                </div>
                <label for="shared_mount_point_domain">
                    <select name="shared_mount_point_domain" id="shared_mount_point_domain">
                        <!-- BDP: shared_mount_point_domain -->
                        <option value="{DOMAIN_NAME}"{SHARED_MOUNT_POINT_DOMAIN_SELECTED}>{DOMAIN_NAME_UNICODE}</option>
                        <!-- EDP: shared_mount_point_domain -->
                    </select>
                </label>
            </td>
        </tr>
        <!-- EDP: shared_mount_point_option -->
        <tr>
            <td>{TR_URL_FORWARDING} <span class="icon i_help" title="{TR_URL_FORWARDING_TOOLTIP}"></span></td>
            <td>
                <div class="radio">
                    <input type="radio" name="url_forwarding" id="url_forwarding_yes"{FORWARD_URL_YES} value="yes">
                    <label for="url_forwarding_yes">{TR_YES}</label>
                    <input type="radio" name="url_forwarding" id="url_forwarding_no"{FORWARD_URL_NO} value="no">
                    <label for="url_forwarding_no">{TR_NO}</label>
                </div>
            </td>
        </tr>
        <tr id="tr_url_forwarding_data">
            <td>{TR_FORWARD_TO_URL}</td>
            <td>
                <label for="forward_url_scheme">
                    <select name="forward_url_scheme" id="forward_url_scheme">
                        <option value="http://"{HTTP_YES}>{TR_HTTP}</option>
                        <option value="https://"{HTTPS_YES}>{TR_HTTPS}</option>
                    </select>
                </label>
                <label>
                    <input name="forward_url" type="text" id="forward_url" value="{FORWARD_URL}">
                </label>
            </td>
        </tr>
        <tr id="tr_type_forwarding_data">
            <td>{TR_FORWARD_TYPE}</td>
            <td>
                <span class="radio">
                    <input type="radio" name="forward_type" id="forward_type_301"{FORWARD_TYPE_301} value="301">
                    <label for="forward_type_301">{TR_301}</label>
                    <input type="radio" name="forward_type" id="forward_type_302"{FORWARD_TYPE_302} value="302">
                    <label for="forward_type_302">{TR_302}</label>
                    <input type="radio" name="forward_type" id="forward_type_303"{FORWARD_TYPE_303} value="303">
                    <label for="forward_type_303">{TR_303}</label>
                    <input type="radio" name="forward_type" id="forward_type_307"{FORWARD_TYPE_307} value="307">
                    <label for="forward_type_307">{TR_307}</label>
                    <input type="radio" name="forward_type" id="forward_type_proxy"{FORWARD_TYPE_PROXY} value="proxy">
                    <label for="forward_type_proxy">{TR_PROXY}</label>
                </span>
                <span class="checkbox">
                    <input type="checkbox" name="forward_host" id="forward_host"{FORWARD_HOST}>
                    <label for="forward_host">{TR_PROXY_PRESERVE_HOST}</label>
                </span>
            </td>
        </tr>
        </tbody>
    </table>
    <div class="buttons">
        <input name="Submit" type="submit" value="{TR_ADD}">
        <a class="link_as_button" href="domains_manage.php">{TR_CANCEL}</a>
    </div>
</form>
