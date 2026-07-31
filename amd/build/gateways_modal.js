define([], function() {
    return {
        process: function(component, paymentArea, itemId, description) {
            location.href = M.cfg.wwwroot + '/payment/gateway/paddle/pay.php' +
                '?component=' + encodeURIComponent(component) +
                '&paymentarea=' + encodeURIComponent(paymentArea) +
                '&itemid=' + encodeURIComponent(itemId);
            return new Promise(function() {});
        }
    };
});
