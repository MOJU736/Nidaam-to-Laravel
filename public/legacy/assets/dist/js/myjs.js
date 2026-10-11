/* Stops the browser's own "scroll the mouse wheel over a number input =
   change its value" behavior, app-wide - the other half of the Sept 2026
   fix that hid the spinner arrows themselves (see mystyle.css). Without
   this, simply scrolling the page down while the cursor happens to be
   sitting over a focused Qty/Unit Price/tax % box silently changes that
   field's value with no click needed at all - arguably the more
   dangerous version of the same bug on a financial form, since nothing
   about scrolling a page looks like an edit action. Not scoped to any
   one form/class on purpose - reusing document.activeElement here
   catches every number input everywhere, present and future, the same
   "fix once in the shared file, fixed everywhere" way as everything else
   in this file. Deliberately just blurs the field (lets the page keep
   scrolling normally) rather than calling preventDefault() on the wheel
   event itself, which would also block the page from scrolling at all
   while the cursor sits over the box - blur is enough to stop Chrome's
   built-in step-the-value behavior once the input no longer has focus. */
document.addEventListener('wheel', function () {
    var active = document.activeElement;
    if (active && active.tagName === 'INPUT' && active.type === 'number') {
        active.blur();
    }
}, { passive: true });

/* Arrow Up/Down inside a Qty or Unit Price box on an item table (Invoice/
   Quotation/Bill - invoice_add.php, quotation_add.php, bill_add.php all
   share the same tr.item-row / .item-qty / .item-price markup) jumps to
   the SAME box one row up/down instead - spreadsheet-style column
   navigation, not the browser's native "arrow key nudges a number input's
   value up/down by 1" behavior, which is what was actually happening
   before this (Sept 2026: "i don't want to increase and decrease the
   numbers i want to jump to the next below column on the same box
   b[e]lower" - the down-arrow SPINNER ARROW itself was already removed in
   mystyle.css/the wheel handler just above, but the down-arrow KEY on the
   keyboard still fell through to the browser's own step-the-value
   handling until now). One delegated listener here (not per-form JS)
   covers every item table across the app, present and future, the same
   "fix once in the shared file" way as everything else in this file.
   preventDefault() unconditionally on these keys while focused in one of
   these boxes - even when there's no row to jump to (first/last row) -
   so the value never gets nudged as a side effect either way. */
/* Safe hand-rolled arithmetic parser/evaluator for the Qty/Unit Price
   "accept a calculation" feature just below - deliberately NOT eval() or
   `new Function(...)`, since this app's own CSP already blocks eval
   outright in this browser (see the CSP comment further down this file)
   and a real expression evaluator has no business running arbitrary JS
   from a text box anyway. Supports + - * / with normal precedence,
   parentheses, unary +/-, and decimals; rejects anything else (letters,
   stray characters, unbalanced parens, a trailing operator, division by
   zero) by returning null rather than guessing. Recursive-descent:
   parseExpr (+ -) -> parseTerm (* /) -> parseFactor (unary, parens,
   numbers) - standard shape, not app-specific. */
function nidaamSafeEvalArithmetic(expr) {
    if (!/^[0-9.\s+\-*/()]+$/.test(expr)) { return null; }

    var pos = 0;

    function peek() { return expr.charAt(pos); }
    function skipSpace() { while (peek() === ' ' || peek() === '\t') { pos++; } }

    function parseNumber() {
        skipSpace();
        var start = pos;
        var sawDot = false;
        while (pos < expr.length) {
            var c = peek();
            if (c >= '0' && c <= '9') { pos++; continue; }
            if (c === '.' && !sawDot) { sawDot = true; pos++; continue; }
            break;
        }
        if (pos === start || (pos - start === 1 && sawDot)) { return null; }
        var n = parseFloat(expr.slice(start, pos));
        return isNaN(n) ? null : n;
    }

    function parseFactor() {
        skipSpace();
        var c = peek();
        if (c === '+') { pos++; return parseFactor(); }
        if (c === '-') { pos++; var v = parseFactor(); return v === null ? null : -v; }
        if (c === '(') {
            pos++;
            var inner = parseExpr();
            if (inner === null) { return null; }
            skipSpace();
            if (peek() !== ')') { return null; }
            pos++;
            return inner;
        }
        return parseNumber();
    }

    function parseTerm() {
        var v = parseFactor();
        if (v === null) { return null; }
        for (;;) {
            skipSpace();
            var op = peek();
            if (op !== '*' && op !== '/') { break; }
            pos++;
            var rhs = parseFactor();
            if (rhs === null) { return null; }
            if (op === '*') {
                v = v * rhs;
            } else {
                if (rhs === 0) { return null; }
                v = v / rhs;
            }
        }
        return v;
    }

    function parseExpr() {
        var v = parseTerm();
        if (v === null) { return null; }
        for (;;) {
            skipSpace();
            var op = peek();
            if (op !== '+' && op !== '-') { break; }
            pos++;
            var rhs = parseTerm();
            if (rhs === null) { return null; }
            v = (op === '+') ? v + rhs : v - rhs;
        }
        return v;
    }

    var result = parseExpr();
    skipSpace();
    if (pos !== expr.length) { return null; } // leftover, unparsed characters
    return result;
}

/* Evaluates whatever's typed in a Qty/Unit Price box (see the type="text"
   change on these fields in invoice_add.php/quotation_add.php/
   bill_add.php/purchase_order_add.php - Sept 2026 request: "inside a Qty
   or Unit Price box must accept calculations like divide, multiply,
   adding and also subtracting") and replaces it with the computed
   number - a plain "5" evaluates straight back to 5, so this is harmless
   to run on every value, expression or not. Leaves the field completely
   alone (whatever was typed, typo or otherwise) when it doesn't parse as
   a valid expression, same as before this feature existed. */
function nidaamEvaluateCalcField(input) {
    var raw = input.value.trim();
    if (!raw) { return; }

    var result = nidaamSafeEvalArithmetic(raw);
    if (result === null || !isFinite(result)) { return; }

    /* Qty is stored as decimal(15,2) in every one of these tables - always
       exactly 2 decimal places - so a Qty calculation is rounded to 2dp
       right here too; otherwise what's shown in the box (e.g. a 3-decimal
       division result) would silently differ from what MySQL actually
       ends up saving. Unit Price has no such fixed cap (its column
       follows the account's own configured decimal_places, and this app
       already shows/saves a >2dp unit price in practice - e.g. a
       weighted-average cost like 1.485) - so a Unit Price result only
       gets float-noise cleanup (0.1+0.2 leaving a 17-digit tail), not
       forced 2dp rounding. */
    var decimalsToKeep = input.classList.contains('item-qty') ? 2 : 6;
    var normalized = parseFloat(result.toFixed(decimalsToKeep));
    if (!isFinite(normalized)) { return; }

    input.value = normalized;
    /* recalcRow()/getSubtotal() etc. (invoice_add.php and its siblings)
       are all bound to this same 'input' event - dispatching it here is
       what makes the Amount column and the invoice/bill total pick up
       the computed value immediately, without needing an extra keystroke
       in the box afterward. */
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

document.addEventListener('keydown', function (e) {
    var input = e.target;
    if (!input || input.tagName !== 'INPUT') { return; }
    if (!input.classList.contains('item-qty') && !input.classList.contains('item-price')) { return; }

    if (e.key === 'Enter') {
        /* These boxes sit inside the invoice/quotation/bill/PO form - Enter
           would otherwise submit the whole form mid-calculation (e.g.
           right after typing "20/4", before the box even has a chance to
           evaluate it). Evaluate in place and stay put instead. */
        e.preventDefault();
        nidaamEvaluateCalcField(input);
        return;
    }

    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') { return; }

    e.preventDefault();

    var currentRow = input.closest('tr');
    if (!currentRow || !currentRow.parentElement) { return; }
    var rows = Array.prototype.filter.call(currentRow.parentElement.children, function (el) {
        return el.tagName === 'TR';
    });
    var targetRow = rows[rows.indexOf(currentRow) + (e.key === 'ArrowDown' ? 1 : -1)];
    if (!targetRow) { return; }

    /* Same class first (plain case - a normal Qty/Unit Price box in the
       target row too). Falls back to whatever's actually focusable in
       the SAME column position when the direct class match isn't - e.g.
       the target row's Unit Price is mid multi-vehicle price breakdown,
       where the plain .item-price input becomes a hidden rollup and the
       real, editable boxes are the .item-price-sub ones inside it. */
    var candidate = targetRow.querySelector('.' + (input.classList.contains('item-qty') ? 'item-qty' : 'item-price') + ':not([type="hidden"])');
    if (!candidate) {
        var cellIndex = Array.prototype.indexOf.call(currentRow.children, input.closest('td'));
        var targetCell = targetRow.children[cellIndex];
        candidate = targetCell ? targetCell.querySelector('input:not([type="hidden"]), select') : null;
    }
    if (candidate) {
        candidate.focus();
        if (typeof candidate.select === 'function') { candidate.select(); }
    }
});

/* The blur/focusout counterpart to the Enter-key handling above - clicking
   or Tabbing out of a Qty/Unit Price box (rather than pressing Enter)
   evaluates it the same way. 'focusout' (not 'blur') because blur doesn't
   bubble, and this needs one delegated document-level listener rather
   than binding to every box individually (this file has no per-row JS of
   its own - invoice_add.php etc. clone rows client-side, and a
   document-level listener needs no rebinding when that happens). */
document.addEventListener('focusout', function (e) {
    var input = e.target;
    if (!input || input.tagName !== 'INPUT') { return; }
    if (!input.classList.contains('item-qty') && !input.classList.contains('item-price')) { return; }
    nidaamEvaluateCalcField(input);
});

$(document).ready(function() {
    $('input[name="reset"]').click(function(e) {
        location = location.href;
    });

    $('select.select').not(".skip").select2({width:'100%'});

});

/* Customers > view.php's "Unbilled Transactions" panel - in its own
   separate $(document).ready() call, deliberately NOT merged into the
   one above. If anything earlier in that shared callback throws (this
   browser's environment already shows a real, page-level CSP violation
   in the console - "Content Security Policy of your site blocks the
   use of `eval`" - and any one of the many third-party plugins loaded
   sitewide, e.g. select2 just above, could be the source of it), a
   synchronous exception partway through a ready-callback stops
   everything queued after it in that SAME callback from ever running -
   which would silently take this binding down with it even though
   nothing is wrong with this code itself. A separate ready() call is
   its own boundary, so this still runs regardless of what happens in
   any other one. (Sept 2026: reported repeatedly as "still not
   working" / "not selecting all" after fixes that were each
   individually correct but missed the real cause below.) */
$(document).ready(function() {
    /* Header checkbox mirrors its checked state onto every row's
       .unbilled-expense-check checkbox. A no-op on any other page
       (#unbilledSelectAll simply doesn't exist there).

       TRUE root cause of every earlier failed attempt: _base/foot.php
       runs iCheck on EVERY checkbox/radio site-wide
       ($('input[type="checkbox"],[type="radio"]').not('.skip').iCheck(...)),
       which replaces each checkbox's on-screen appearance with its own
       custom overlay and intercepts clicks itself. Setting the native
       input's .checked via plain jQuery (.prop('checked', ...) bound
       to the native 'change' event, tried previously) silently never
       touches that overlay - the underlying <input> can end up in the
       right state while what the user actually sees never updates,
       which is exactly the "it works only it's not selecting all"
       symptom reported. iCheck instead fires its OWN 'ifChecked' /
       'ifUnchecked' events on the original input when its state
       changes, and exposes '.iCheck('check')' / '.iCheck('uncheck')'
       to change a checkbox's state the way iCheck itself expects -
       this file already had this exact pattern, unused/commented out,
       for an older "check all" feature further down (search
       ifChecked/ifUnchecked) - so this follows that established,
       correct convention instead of plain .prop()/change. Delegated
       via $(document).on(...) rather than bound directly to the
       element, matching that same existing convention. */
    $(document).on('ifChecked', '#unbilledSelectAll', function () {
        $('.unbilled-expense-check').iCheck('check');
    });
    $(document).on('ifUnchecked', '#unbilledSelectAll', function () {
        $('.unbilled-expense-check').iCheck('uncheck');
    });

    /* Same panel's "Create Invoice from Selected" submit button -
       confirm before actually creating the draft invoice. Also moved
       out of an inline onclick= for the same reason. A no-op on any
       other page (#billExpensesSubmit doesn't exist there). */
    $('#billExpensesSubmit').on('click', function (e) {
        if (!confirm('Create a draft invoice from the selected transactions?')) {
            e.preventDefault();
        }
    });
});

function price_input_C(x) {
    var v = x.split('__');

    if (v[1] === 'C') {
        return v[0];
    } else {
        return '-'
    }
}

function price_input_D(x) {
    var v = x.split('__');

    if (v[1] === 'D') {
        return v[0];
    } else {
        return '-'
    }
}

function clearLocalStorage() {
    bootbox.confirm(lang.r_u_sure, function (result) {
        if (result) {
            localStorage.clear();
            // location.reload(true);
            location = location.href;
        }
    }); 
}

// var ids = null;

// function getIDs() {
// 	ids = [];
// 	$('.checktd:checked').each(function() {
// 		ids.push(this.value);
// 	});
// }

// function row_status(x) {
//     if(x == null) {
//         return '';
//     } else if(x == 'pending') {
//         return '<div class="text-center" style="cursor:pointer;"><span class="row_status label label-warning">'+lang[x]+'</span></div>';
//     } else if(x == 'completed' || x == 'paid' || x == 'sent' || x == 'received') {
//         return '<div class="text-center" style="cursor:pointer;"><span class="row_status label label-success">'+lang[x]+'</span></div>';
//     } else if(x == 'partial' || x == 'transferring' || x == 'ordered') {
//         return '<div class="text-center" style="cursor:pointer;"><span class="row_status label label-info">'+lang[x]+'</span></div>';
//     } else if(x == 'due' || x == 'returned') {
//         return '<div class="text-center" style="cursor:pointer;"><span class="row_status label label-danger">'+lang[x]+'</span></div>';
//     } else {
//         return '<div class="text-center" style="cursor:pointer;"><span class="row_status label label-default">'+x+'</span></div>';
//     }
// }
// function is_valid_discount(mixed_var) {
//     return (is_numeric(mixed_var) || (/([0-9]%)/i.test(mixed_var))) ? true : false;
// }
// function check_add_item_val() {
//     $('#add_item').bind('keypress', function (e) {
//         if (e.keyCode == 13 || e.keyCode == 9) {
//             e.preventDefault();
//             $(this).autocomplete("search");
//         }
//     });
// }

// function printDiv(page_title) {
//     var divToPrint=document.getElementById('print');
//     var newWin=window.open('','Print-Window');
//     newWin.document.open();
//     newWin.document.write('<html><head><title>' + page_title + '</title>');
//     newWin.document.write('<link rel="stylesheet" type="text/css" href="'+site.base_url+'assets/dist/css/print.css" />');
//     newWin.document.write('</head><body onload="window.print()"><style>.no-print{display: none;}</style>');
//     newWin.document.write(divToPrint.innerHTML);
//     newWin.document.write('</body></html>');
//     newWin.document.close();
// }

// function is_numeric(mixed_var) {
//     var whitespace =
//     " \n\r\t\f\x0b\xa0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200a\u200b\u2028\u2029\u3000";
//     return (typeof mixed_var === 'number' || (typeof mixed_var === 'string' && whitespace.indexOf(mixed_var.slice(-1)) === -
//         1)) && mixed_var !== '' && !isNaN(mixed_var);
// }


// $.extend(
//     true,
//     $.fn.dataTable.defaults,
//     {
//         sDom:"<'row'<'col-md-6 text-left'l><'col-md-6 text-right'f>r>t<'row'<'col-md-6 text-left'i><'col-md-6 text-right'p>>",
//         sPaginationType:"bootstrap",
//         "fnDrawCallback":function() {
//             $(".tip").tooltip({html: true});
//             $(".popnote").popover();
//             $('.checkbox').iCheck({
//                 checkboxClass:'icheckbox_square-green',
//                 radioClass:'iradio_square-green',
//                 increaseArea:'20%'
//             });
//             $("input").addClass('input-xs');
//             $("select").addClass('select input-xs');
//             $(".select").select2();
//         }
//     }
// );
// $.extend(
//     $.fn.dataTableExt.oStdClasses,
//     {
//         sWrapper:"dataTables_wrapper form-inline"
//     }
// );
// $.fn.dataTableExt.oApi.fnPagingInfo=function(a) {
//     return {
//         iStart:a._iDisplayStart,
//         iEnd:a.fnDisplayEnd(),
//         iLength:a._iDisplayLength,
//         iTotal:a.fnRecordsTotal(),
//         iFilteredTotal:a.fnRecordsDisplay(),
//         iPage:a._iDisplayLength===-1?0:Math.ceil(a._iDisplayStart/a._iDisplayLength),
//         iTotalPages:a._iDisplayLength===-1?0:Math.ceil(a.fnRecordsDisplay()/a._iDisplayLength)
//     }
// };
// $.extend(
//     $.fn.dataTableExt.oPagination, {
//         bootstrap: {
//             fnInit:function(e,b,d) {
//                 var a=e.oLanguage.oPaginate;
//                 var f=function(g) {
//                     g.preventDefault();
//                     if(e.oApi._fnPageChange(e,g.data.action)) {
//                         d(e)
//                     }
//                 };
//                 $(b).append('<ul class="pagination pagination-sm"><li class="prev disabled"><a href="#"> '+a.sPrevious+'</a></li><li class="next disabled"><a href="#">'+a.sNext+" </a></li></ul>");
//                 var c=$("a",b);
//                 $(c[0]).bind("click.DT", {
//                     action:"previous"
//                 },f);
//                 $(c[1]).bind("click.DT", {
//                     action:"next"
//                 },f)
//             },
//             fnUpdate:function(c,k) {
//                 var l=5;
//                 var e=c.oInstance.fnPagingInfo();
//                 var h=c.aanFeatures.p;
//                 var g,m,f,d,a,n,b=Math.floor(l/2);
//                 if(e.iTotalPages<l) {
//                     a=1;n=e.iTotalPages
//                 } else {
//                     if(e.iPage<=b) {
//                         a=1;
//                         n=l
//                     } else {
//                         if(e.iPage>=(e.iTotalPages-b)) {
//                             a=e.iTotalPages-l+1;
//                             n=e.iTotalPages
//                         } else {
//                             a=e.iPage-b+1;
//                             n=a+l-1
//                         }
//                     }
//                 }
//                 for(g=0,m=h.length;g<m;g++) {
//                     $("li:gt(0)",h[g]).filter(":not(:last)").remove();
//                     for(f=a;f<=n;f++) {
//                         d=(f==e.iPage+1)?'class="active"':"";
//                         $("<li "+d+'><a href="#">'+f+"</a></li>").insertBefore($("li:last",h[g])[0]).bind("click", function(i) {
//                             i.preventDefault();
//                             c._iDisplayStart=(parseInt($("a",this).text(),10)-1)*e.iLength;
//                             k(c)
//                         })
//                     }
//                     if(e.iPage===0) {
//                         $("li:first",h[g]).addClass("disabled");
//                     } else {
//                         $("li:first",h[g]).removeClass("disabled");
//                     }
//                     if(e.iPage===e.iTotalPages-1||e.iTotalPages===0) {
//                         $("li:last",h[g]).addClass("disabled");
//                     } else {
//                         $("li:last",h[g]).removeClass("disabled");
//                     }
//                 }
//             }
//         }
//     }
// );
// if($.fn.DataTable.TableTools) {
//     $.extend(
//         true,
//         $.fn.DataTable.TableTools.classes,
//         {
//             container:"btn-group",
//             buttons: {
//                 normal:"btn btn-sm btn-primary",
//                 disabled:"disabled"
//             },
//             collection: {
//                 container:"DTTT_dropdown dropdown-menu",
//                 buttons:{
//                     normal:"",
//                     disabled:"disabled"
//                 }
//             },
//             print: {
//                 info:"DTTT_print_info modal"
//             },
//             select: {
//                 row:"active"
//             }
//         }
//     );
//     $.extend(
//         true,
//         $.fn.DataTable.TableTools.DEFAULTS.oTags,
//         {
//             collection: {
//                 container:"ul",
//                 button:"li",
//                 liner:"a"
//             }
//         }
//     )
// };

// $('#supplier, #rsupplier, .rsupplier').select2({
//     ajax: {
//         url: site.base_url+"people/supplier_suggestions",
//         dataType: 'json',
//         delay: 250,
//         data: function (params) {
//           return {
//             term: params.term, // search term
//             limit: 10
//           };
//         },
//         processResults: function (data, params) {
//             if(data.results != null) {
//                 return { results: data.results };
//             } else {
//                 return { results: [{id: '', text: globalLang.no_match_found}]};
//             }
//         },
//         cache: true
//     },
//     placeholder: globalLang.search_sippliers,
//     minimumInputLength: 1,
// });

// function fld(oObj) {
//     if (oObj != null) {
//         var aDate = oObj.split('-');
//         var bDate = aDate[2].split(' ');
//         year = aDate[0], month = aDate[1], day = bDate[0], time = bDate[1];
//         if (site.dateFormats.js_sdate == 'dd-mm-yyyy')
//             return day + "-" + month + "-" + year + " " + time;
//         else if (site.dateFormats.js_sdate === 'dd/mm/yyyy')
//             return day + "/" + month + "/" + year + " " + time;
//         else if (site.dateFormats.js_sdate == 'dd.mm.yyyy')
//             return day + "." + month + "." + year + " " + time;
//         else if (site.dateFormats.js_sdate == 'mm/dd/yyyy')
//             return month + "/" + day + "/" + year + " " + time;
//         else if (site.dateFormats.js_sdate == 'mm-dd-yyyy')
//             return month + "-" + day + "-" + year + " " + time;
//         else if (site.dateFormats.js_sdate == 'mm.dd.yyyy')
//             return month + "." + day + "." + year + " " + time;
//         else
//             return oObj;
//     } else {
//         return '';
//     }
// }

// function formatQuantity(x) {
//     return (x != null) ? '<div class="text-center">'+formatNumber(x, site.settings.qty_decimals)+'</div>' : '';
// }
// function formatQuantity2(x) {
//     return (x != null) ? formatQuantityNumber(x, site.settings.qty_decimals) : '';
// }
// function formatQuantityNumber(x, d) {
//     if (!d) { d = site.settings.qty_decimals; }
//     return parseFloat(accounting.formatNumber(x, d, '', '.'));
// }
// function attachment(x) {
//     return x == null ? '' : '<div class="text-center"><a href="'+site.base_url+'inventory_settings/download/' + x + '" class="tip" title="'+lang.download+'"><i class="fa fa-file"></i></a></div>';
// }
// function currencyFormat(x, y = null) {
//     if (y == 1) {
//         return '<div class="text-center">'+formatMoney(x != null ? x : 0)+'</div>';
//     }
//     if (x == null) {
//         return formatNumber('0', site.settings.qty_decimals);
//     }
//     value = x.split('-');
//     return '<div class="text-right">'+formatMoney(value[0] != null ? value[0] : 0, value[1])+'</div>';
// }
// function pay_status(x) {
//     if(x == null) {
//         return '';
//     } else if(x == 'pending') {
//         return '<div class="text-center"><span class="payment_status label label-warning">'+lang[x]+'</span></div>';
//     } else if(x == 'completed' || x == 'paid' || x == 'sent' || x == 'received') {
//         return '<div class="text-center"><span class="payment_status label label-success">'+lang[x]+'</span></div>';
//     } else if(x == 'partial' || x == 'transferring' || x == 'ordered') {
//         return '<div class="text-center"><span class="payment_status label label-info">'+lang[x]+'</span></div>';
//     } else if(x == 'due' || x == 'returned') {
//         return '<div class="text-center"><span class="payment_status label label-danger">'+lang[x]+'</span></div>';
//     } else {
//         return '<div class="text-center"><span class="payment_status label label-default">'+x+'</span></div>';
//     }
// }
// function decimalFormat(x) {
//     return '<div class="text-center">'+formatNumber(x != null ? x : 0)+'</div>';
// }

// function formatNumber(x, d) {
//     if(!d && d != 0) { d = site.settings.decimals; }
//     // if(site.settings.sac == 1) {
//     //     return formatSA(parseFloat(x).toFixed(d));
//     // }
//     return accounting.formatNumber(x, d, site.settings.thousands_sep == 0 ? ' ' : site.settings.thousands_sep, site.settings.decimals_sep);
// }

// function formatMoney(x, symbol) {
//     if(!symbol) { symbol = mAccountSettings.currency_symbol; } else {
//         $.each(currencies, function() {
//             console.log(this.id);
//             console.log(this.symbol);
//             if (this.id == symbol) {
//                 if (this.symbol) {
//                     symbol = this.symbol;
//                 } else {
//                     symbol = this.code;
//                 }
                
//             }
//         });
//     }
//     // if(site.settings.sac == 1) {
//     //     return (site.settings.display_symbol == 1 ? site.settings.symbol : '') +
//     //         ''+formatSA(parseFloat(x).toFixed(site.settings.decimals)) +
//     //         (site.settings.display_symbol == 2 ? site.settings.symbol : '');
//     // }
//     var fmoney = accounting.formatMoney(x, symbol, site.settings.decimals, site.settings.thousands_sep == 0 ? ' ' : site.settings.thousands_sep, site.settings.decimals_sep, "%s%v");
//     return (site.settings.display_symbol == 1 ? site.settings.symbol : '') +
//         fmoney +
//         (site.settings.display_symbol == 2 ? site.settings.symbol : '');
// }

// function checkbox(x) {
//     return '<div class="text-center"><input type="checkbox" name="val[]" value="' + x + '" class="checkbox multi-select checktd"></div>';
// }

// function img_hl(x) {
// 	var rand =  Math.floor((Math.random() * 1000) + 1);
//     var image_link = (x == null || x == '') ? 'no_image.png' : x;
//     return '<div class="text-center"><a href="' + site.base_url + 'assets/uploads/images/' + image_link + '" data-lightbox="' + image_link + '_' + rand + '"><img src="' + site.base_url + 'assets/uploads/images/thumbs/' + image_link + '" alt="" style="width:30px; height:30px;" /></a></div>';
// }

// function formatSA (x) {
//     x=x.toString();
//     var afterPoint = '';
//     if(x.indexOf('.') > 0)
//        afterPoint = x.substring(x.indexOf('.'),x.length);
//     x = Math.floor(x);
//     x=x.toString();
//     var lastThree = x.substring(x.length-3);
//     var otherNumbers = x.substring(0,x.length-3);
//     if(otherNumbers != '')
//         lastThree = ',' + lastThree;
//     var res = otherNumbers.replace(/\B(?=(\d{2})+(?!\d))/g, ",") + lastThree + afterPoint;

//     return res;
// }
// function unitToBaseQty(qty, unitObj) {
//     switch(unitObj.operator) {
//         case '*':
//             return parseFloat(qty)*parseFloat(unitObj.operation_value);
//             break;
//         case '/':
//             return parseFloat(qty)/parseFloat(unitObj.operation_value);
//             break;
//         case '+':
//             return parseFloat(qty)+parseFloat(unitObj.operation_value);
//             break;
//         case '-':
//             return parseFloat(qty)-parseFloat(unitObj.operation_value);
//             break;
//         default:
//             return parseFloat(qty);
//     }
// }
// function baseToUnitQty(qty, unitObj) {
//     switch(unitObj.operator) {
//         case '*':
//             return parseFloat(qty)/parseFloat(unitObj.operation_value);
//             break;
//         case '/':
//             return parseFloat(qty)*parseFloat(unitObj.operation_value);
//             break;
//         case '+':
//             return parseFloat(qty)-parseFloat(unitObj.operation_value);
//             break;
//         case '-':
//             return parseFloat(qty)+parseFloat(unitObj.operation_value);
//             break;
//         default:
//             return parseFloat(qty);
//     }
// }
// function set_page_focus() {
//     if (site.settings.set_focus == 1) {
//         $('#add_item').attr('tabindex', an);
//         $('[tabindex='+(an-1)+']').focus().select();
//     } else {
//         $('#add_item').attr('tabindex', 1);
//         $('#add_item').focus();
//     }
//     $('.rquantity').bind('keypress', function (e) {
//         if (e.keyCode == 13) {
//             $('#add_item').focus();
//         }
//     });
// }
// function calculateTax(tax, amt, met) {
//     if (tax && tax_rates) {
//         tax_val = 0; tax_rate = '';
//         $.each(tax_rates, function() {
//             if (this.id == tax) {
//                 tax = this;
//                 return false;
//             }
//         });
//         if (tax.type == 1) {
//             if (met == '0') {
//                 tax_val = formatDecimal(((amt) * parseFloat(tax.rate)) / (100 + parseFloat(tax.rate)), 4);
//                 tax_rate = formatDecimal(tax.rate) + '%';
//             } else {
//                 tax_val = formatDecimal(((amt) * parseFloat(tax.rate)) / 100, 4);
//                 tax_rate = formatDecimal(tax.rate) + '%';
//             }
//         } else if (tax.type == 2) {
//             tax_val = parseFloat(tax.rate);
//             tax_rate = formatDecimal(tax.rate);
//         }
//         return [tax_val, tax_rate];
//     }
//     return false;
// }
// function calculateDiscount(val, amt) {
//     if (val.indexOf("%") !== -1) {
//         var pds = val.split("%");
//         return formatDecimal((parseFloat(((amt) * parseFloat(pds[0])) / 100)), 4);
//     }
//     return formatDecimal(val);
// }
// function formatQty(x) {
//     return (x != null) ? formatNumber(x, site.settings.qty_decimals) : '';
// }
// function change_currency(id) {
//     if (id) {
//         $.ajax({
//             type: "post",
//             async: false,
//             url: site.base_url + "sales/getCurrencyByID/" + id,
//             dataType: "json",
//             success: function (data) {
//                 if (data) {
//                     $('#currency_id').val(id);
//                     $('._curr1').html(data.row.code);
//                     $('.currency_code').html(data.row.code);
                    
//                 }
//             }
//         });
//     }
// }

// $(document).ready(function() {
// 	$('input[type="checkbox"],[type="radio"]').not('.skip').iCheck({
// 	    checkboxClass: 'icheckbox_square-green',
// 	    radioClass: 'iradio_square-green',
// 	    increaseArea: '20%'
// 	});

//     $('div.dataTables_filter input').addClass('form-control');

//     /* Calculate date range in javascript */
//     startDate = new Date((new Date(mAccountSettings.fy_start).getTime())  + (new Date().getTimezoneOffset() * 60 * 1000));
//     endDate = new Date((new Date(mAccountSettings.fy_end).getTime())  + (new Date().getTimezoneOffset() * 60 * 1000));
    
//     if (site.dateFormats) {
//         var date_time_formats = site.dateFormats.js_ldate.split(' ');
//         var date_format = date_time_formats[0];
//         var time_format = date_time_formats[1];
//     }
    
//     $('.datetime').not('.skip').datetimepicker({
//         dateFormat: date_format,
//         timeFormat: time_format,
//         minDate: startDate,
//         maxDate: endDate,
//         fontAwesome: true,
//         language: 'main',
//         autoclose: 1,
//         todayBtn: 1, 
//         todayHighlight: 1,
//         timeInput: true,
//         minView: 2 
//     });
//     if ($('.datetime').val()) {} else {
//         $('.datetime').datetimepicker('setDate', (new Date()));
//     }

//     $('.date').datepicker({
//         dateFormat: site.dateFormats.js_sdate,
//         minDate: startDate,
//         maxDate: endDate,
//         fontAwesome: true,        
//         language: 'main', 
//         autoclose: 1,
//         todayBtn: 1,
//         todayHighlight: 1,
//         minView: 2 
//     });
//     if ($('.date').val()) {} else {
//         $('.date').datepicker('setDate', (new Date()));
//     }


//     $('#myModal').on('hidden.bs.modal', function() {
//         $(this).find('.modal-dialog').empty();
//         //$(this).find('#myModalLabel').empty().html('&nbsp;');
//         //$(this).find('.modal-body').empty().text('Loading...');
//         //$(this).find('.modal-footer').empty().html('&nbsp;');
//         $(this).removeData('bs.modal');
//     });
//     $('#myModal2').on('hidden.bs.modal', function () {
//         $(this).find('.modal-dialog').empty();
//         //$(this).find('#myModalLabel').empty().html('&nbsp;');
//         //$(this).find('.modal-body').empty().text('Loading...');
//         //$(this).find('.modal-footer').empty().html('&nbsp;');
//         $(this).removeData('bs.modal');
//         $('#myModal').css('zIndex', '1050');
//         $('#myModal').css('overflow-y', 'scroll');
//     });
//     $('#myModal2').on('show.bs.modal', function () {
//         $('#myModal').css('zIndex', '1040');
//     });
//     $('.modal').on('show.bs.modal', function () {
//         $('#modal-loading').show();
//         $('.blackbg').css('zIndex', '1041');
//         $('.loader').css('zIndex', '1042');
//     }).on('hide.bs.modal', function () {
//         $('#modal-loading').hide();
//         $('.blackbg').css('zIndex', '3');
//         $('.loader').css('zIndex', '4');
//     });
//     $('body').on('click', '.delivery_link td:not(:first-child, :nth-last-child(2), :nth-last-child(3), :last-child)', function() {
//         $('#myModal').modal({remote: site.base_url + 'sales/view_delivery/' + $(this).parent('.delivery_link').attr('id')});
//         $('#myModal').modal('show');
//     });
//     $(document).on('click', '[data-toggle="ajax"]', function(e) {
//         e.preventDefault();
//         var href = $(this).attr('href');
//         $.get(href, function( data ) {
//             $("#myModal").html(data).modal();
//         });
//     });
// });

// $('.gen_slug').change(function(e) {
//     var title = $(this).val();
//     var slug_url = site.base_url+"inventory_settings/slug";
//     $.get(slug_url, {title: title, type: 'brands'}, function (slug) {
//         $('.slug').val(slug).change();
//     });
// });

// $('#random_num').click(function(){
//     $(this).parent('.input-group').children('input').val(generateCardNo(8));
// });

// function formatDecimal(x, d) {
//     if (!d) { d = site.settings.decimals; }
//     return parseFloat(accounting.formatNumber(x, d, '', '.'));
// }

// function formatDecimals(x, d) {
//     if (!d) { d = site.settings.decimals; }
//     return parseFloat(accounting.formatNumber(x, d, '', '.')).toFixed(d);
// }

// function suppliers(ele) {
//     $(ele).select2({
//         ajax: {
//             url: site.base_url+"people/supplier_suggestions",
//             dataType: 'json',
//             delay: 250,
//             data: function (params) {
//               return {
//                 term: params.term, // search term
//                 limit: 10
//               };
//             },
//             processResults: function (data, params) {
//                 if(data.results != null) {
//                     return { results: data.results };
//                 } else {
//                     return { results: [{id: '', text: globalLang.no_match_found}]};
//                 }
//             },
//             cache: true
//         },
//         placeholder: globalLang.search_sippliers,
//         minimumInputLength: 1
//     });
// }

// function generateCardNo(x) {
//     if(!x) {
//         x = 16;
//     }
//     chars = "1234567890";
//     no = "";
//     for (var i=0; i<x; i++) {
//        var rnum = Math.floor(Math.random() * chars.length);
//        no += chars.substring(rnum,rnum+1);
//    }
//    return no;
// }

// function getSlug(title, type) {
//     var slug_url = site.base_url+'inventory_settings/slug';
//     $.get(slug_url, {title: title, type: type}, function (slug) {
//         $('#slug').val(slug).change();
//     });
// }

// $(document).on('ifChecked', '.checkth, .checkft', function(event) {
//     $('.checkth, .checkft').iCheck('check');
//     $('.multi-select').each(function() {
//         $(this).iCheck('check');
//     });
// });

// $(document).on('ifUnchecked', '.checkth, .checkft', function(event) {
//     $('.checkth, .checkft').iCheck('uncheck');
//     $('.multi-select').each(function() {
//         $(this).iCheck('uncheck');
//     });
// });

// $(document).on('ifUnchecked', '.multi-select', function(event) {
//     $('.checkth, .checkft').attr('checked', false);
//     $('.checkth, .checkft').iCheck('update');
// });

/**
 * Generic client-side "quick filter" search box for a record list table
 * that's already fully rendered server-side (no AJAX paging) - as the
 * user types into `input`, each data row of `table`'s <tbody> is shown
 * only if its own text contains what was typed (case-insensitive; matches
 * across every visible column at once, so typing a SKU, a category, a
 * status, etc. all work the same as typing a name). Added site-wide (Sept
 * 2026 request: "add filter all my app forms") to every plain record list
 * that didn't already have one - see items/index.php, customers/index.php,
 * vendors/index.php, commissions/index.php, and the various
 * sales/purchases *_index.php views for how each one wires it up. Journal
 * Entries and the Chart of Accounts list already have their own
 * DataTables-powered search box built in, so they don't use this.
 *
 * Rows that shouldn't ever be treated as searchable data - an existing
 * "No items yet, add your first..." placeholder row, or a dedicated
 * "No results match your search" row this same helper toggles - are
 * marked with the `js-quick-filter-skip` class so they're left alone
 * either way, never hidden by a real search term and never counted
 * towards whether anything matched.
 */
function attachQuickFilter(input, table) {
    if (!input || !table) { return; }
    var noMatchRow = table.querySelector('.js-quick-filter-empty');

    function apply() {
        var term = input.value.trim().toLowerCase();
        var rows = table.querySelectorAll('tbody > tr');
        var visibleCount = 0;

        rows.forEach(function (row) {
            if (row.classList.contains('js-quick-filter-skip')) { return; }
            var isMatch = !term || row.textContent.toLowerCase().indexOf(term) !== -1;
            row.style.display = isMatch ? '' : 'none';
            if (isMatch) { visibleCount++; }
        });

        if (noMatchRow) {
            noMatchRow.style.display = (term && visibleCount === 0) ? '' : 'none';
        }
    }

    input.addEventListener('input', apply);
}


/* =====================================================================
   Payment Reminders (Sept 25 2026) - customers/reminders.php,
   customers/view.php and settings/email.php. Lives here rather than in
   inline <script>/onsubmit= blocks, same as the Unbilled Transactions
   code above, so it keeps working in the browser whose CSP has already
   caused trouble with inline JS. Its own ready() call so nothing thrown
   elsewhere can take it down. Every piece is a no-op on pages that
   don't have its elements.
   ===================================================================== */
$(document).ready(function () {
    /* Simple "are you sure?" on a form or a single button - the message
       is in the element's data-nidaam-confirm attribute. */
    $(document).on('submit', 'form[data-nidaam-confirm]', function (e) {
        if (!window.confirm($(this).attr('data-nidaam-confirm'))) {
            e.preventDefault();
        }
    });
    $(document).on('click', 'button[data-nidaam-confirm]', function (e) {
        if (!window.confirm($(this).attr('data-nidaam-confirm'))) {
            e.preventDefault();
        }
    });

    /* Customer page: "Send Reminder" for a customer with no email on
       file - ask for one (and whether to save it), then post it along
       with the CSRF token form_open() would normally have added. */
    $(document).on('click', '#reminderPromptBtn, .js-reminder-prompt', function () {
        var btn = $(this);
        var email = window.prompt('This customer has no email on file yet.\n\nEnter an email address to send the payment reminder to:');
        if (email === null) { return; }
        email = $.trim(email);
        if (email === '') {
            window.alert('Enter an email address to send to.');
            return;
        }
        var save = window.confirm('Also save "' + email + '" to this customer\'s record for next time?');

        var form = $('<form method="post" style="display:none;"></form>').attr('action', btn.attr('data-action'));
        form.append($('<input type="hidden" name="email">').val(email));
        if (save) {
            form.append($('<input type="hidden" name="save_email" value="1">'));
        }
        if (btn.attr('data-return')) {
            form.append($('<input type="hidden" name="return">').val(btn.attr('data-return')));
        }
        if (btn.attr('data-csrf-name')) {
            form.append($('<input type="hidden">').attr('name', btn.attr('data-csrf-name')).val(btn.attr('data-csrf-hash')));
        }
        $('body').append(form);
        form.trigger('submit');
    });

    /* Payment Reminders page: selected count, tick-all, batch limit and
       a confirm naming how many emails are about to go out. The tick
       boxes carry class "skip" so iCheck leaves them as plain checkboxes
       and the native change event below fires normally. */
    var remindersForm = $('#remindersForm');
    if (remindersForm.length) {
        var limit = parseInt(remindersForm.attr('data-batch-limit'), 10) || 25;
        var sendBtn = $('#remindersSendBtn');
        var countEl = $('#remindersCount');
        var tickedCount = function () {
            return remindersForm.find('input.reminder-check:checked').length;
        };
        var refresh = function () {
            var n = tickedCount();
            countEl.text(n + ' selected' + (n > limit ? ' - up to ' + limit + ' can be sent at a time' : ''));
            sendBtn.prop('disabled', n === 0 || n > limit);
        };
        remindersForm.on('change', 'input.reminder-check', refresh);
        $('#remindersToggleAll').on('change', function () {
            remindersForm.find('input.reminder-check').prop('checked', this.checked);
            refresh();
        });
        remindersForm.on('submit', function (e) {
            var n = tickedCount();
            if (n === 0 || n > limit) {
                e.preventDefault();
                return;
            }
            if (!window.confirm('Email a payment reminder to ' + n + ' customer' + (n === 1 ? '' : 's') + ' now?')) {
                e.preventDefault();
                return;
            }
            /* Disabled AFTER the browser has collected the form data, so a
               double-click can't send every reminder twice. */
            setTimeout(function () {
                sendBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');
            }, 0);
        });
        refresh();
    }
});

/* =========================================================================
 * Apply payments to invoices / bills (Sept 2026 - "link payments to
 * invoices"). Drives views/_partials/_apply_payment.php:
 *   - 'new' mode (Receive Payment / Pay Vendor): loads the chosen
 *     customer's/vendor's unpaid documents as JSON and fills the Amount in
 *     oldest first. Typing in a row takes over from the auto-fill; if the
 *     Amount box was left empty it follows the rows' total instead.
 *   - 'credit' mode (a payment's own page): same table for what's left of
 *     that payment, with a fixed amount available.
 * All money is handled in whole cents (10^decimals) to avoid float drift.
 * ========================================================================= */
$(document).ready(function () {
    $('.js-apply-docs').each(function () {
        var card = $(this);
        var mode = card.attr('data-mode');
        var decimals = parseInt(card.attr('data-decimals'), 10);
        if (isNaN(decimals) || decimals < 0) { decimals = 2; }
        var factor = Math.pow(10, decimals);
        var docWord = card.attr('data-doc-word');
        var creditWord = card.attr('data-credit-word');
        var viewUrl = card.attr('data-view-url');
        var form = card.closest('form');
        var tbody = card.find('tbody');
        var docs = [];
        var touched = false;
        var amountUser = false;
        var loadSeq = 0;
        var focusDoc = parseInt(card.attr('data-focus-doc'), 10) || 0;
        var amountInput = mode === 'new' ? form.find('input[name="amount"]') : $();
        var partySelect = mode === 'new' ? $(card.attr('data-party-select')) : $();

        var toCents = function (v) {
            var n = parseFloat(String(v === undefined || v === null ? '' : v).replace(/,/g, ''));
            return isNaN(n) ? 0 : Math.round(n * factor);
        };
        var fromCents = function (c) { return (c / factor).toFixed(decimals); };
        var money = function (c) {
            var s = fromCents(Math.abs(c)).split('.');
            s[0] = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return (c < 0 ? '-' + (window.ND_CUR || '$') : (window.ND_CUR || '$')) + s.join('.');
        };
        var available = function () {
            if (mode === 'credit') { return toCents(card.attr('data-fixed-amount')); }
            return toCents(amountInput.val());
        };
        var inputs = function () { return tbody.find('input.apply-amt'); };
        var appliedTotal = function () {
            var t = 0;
            inputs().each(function () { t += toCents(this.value); });
            return t;
        };

        var render = function () {
            tbody.empty();
            card.find('.apply-docs__wrap, .apply-docs__bar').toggle(docs.length > 0);
            card.find('.apply-docs__empty').toggle(docs.length === 0);
            $.each(docs, function (i, d) {
                var tr = $('<tr>');
                tr.append($('<td>').append($('<a target="_blank">').attr('href', viewUrl + d.id).text(d.label)));
                tr.append($('<td>').text(d.date));
                var due = $('<td>').text(d.due || '—');
                if (d.overdue) { due.addClass('text-danger').append(' ', $('<span class="badge bg-danger">').text('Overdue')); }
                tr.append(due);
                tr.append($('<td class="text-right">').text(money(toCents(d.total))));
                tr.append($('<td class="text-right">').text(money(toCents(d.balance_due))));
                var input = $('<input type="number" step="any" min="0" class="form-control form-control-sm apply-amt">')
                    .attr('name', 'apply[' + d.id + ']')
                    .attr('data-max', toCents(d.balance_due));
                tr.append($('<td class="text-right">').append(input));
                tbody.append(tr);
            });
        };

        var autoFill = function () {
            var left = available();
            var ordered = docs.slice();
            if (focusDoc) {
                ordered.sort(function (a, b) {
                    return (b.id === focusDoc ? 1 : 0) - (a.id === focusDoc ? 1 : 0);
                });
            }
            var byId = {};
            $.each(ordered, function (i, d) {
                var take = Math.min(left, toCents(d.balance_due));
                byId[d.id] = take > 0 ? take : 0;
                left -= byId[d.id];
            });
            inputs().each(function () {
                var id = parseInt(this.name.replace(/\D+/g, ''), 10);
                this.value = byId[id] ? fromCents(byId[id]) : '';
            });
        };

        var refresh = function () {
            var avail = available();
            var applied = appliedTotal();
            var err = '';
            inputs().each(function () {
                var over = toCents(this.value) > parseInt($(this).attr('data-max'), 10);
                $(this).toggleClass('is-invalid', over);
                if (over && !err) { err = 'One of the amounts is more than that ' + docWord + '\'s balance due.'; }
            });
            if (!err && applied > avail) {
                err = 'You are applying ' + money(applied) + ' but the ' + (mode === 'credit' ? creditWord + ' available' : 'payment') + ' is only ' + money(avail) + '.';
            }
            var left = avail - applied;
            var summary = (mode === 'credit' ? 'Available ' : 'Payment ') + '<strong>' + money(avail) + '</strong>'
                + ' &middot; Applied <strong>' + money(applied) + '</strong>'
                + ' &middot; ' + (mode === 'credit' ? 'Still unapplied ' : 'Left as ' + creditWord + ' ') + '<strong>' + money(left > 0 ? left : 0) + '</strong>';
            card.find('.apply-docs__summary').html(summary);
            card.find('.apply-docs__error').text(err).toggle(!!err);
            form.find('button[type="submit"]').not('.row-actions-toggle').prop('disabled', !!err || (mode === 'credit' && applied <= 0));

            var note = card.find('.apply-docs__note');
            var direct = card.data('directAccount');
            if (direct && applied > 0) {
                note.text('Because this payment pays bills, it will go to Accounts Payable instead of "' + direct + '".').show();
            } else {
                note.hide();
            }
        };

        var load = function () {
            var id = partySelect.val();
            var seq = ++loadSeq;
            docs = [];
            touched = false;
            card.removeData('directAccount');
            if (!id) { render(); refresh(); return; }
            $.getJSON(card.attr('data-open-url') + '/' + encodeURIComponent(id)).done(function (res) {
                if (seq !== loadSeq) { return; }
                docs = (res && res.docs) ? res.docs : [];
                if (res && res.direct_account) { card.data('directAccount', res.direct_account); }
                render();
                var credit = res ? toCents(res.credit) : 0;
                if (credit > 0) {
                    card.find('.apply-docs__empty').show().html('')
                        .append(document.createTextNode('This ' + card.attr('data-party-word') + ' already has ' + money(credit) + ' of unapplied ' + creditWord + '. '))
                        .append($('<a>').attr('href', res.credit_url).text('Apply it from their page'))
                        .append(document.createTextNode(' if it should pay one of these ' + docWord + 's.'));
                }
                if (!amountUser && available() === 0 && focusDoc === 0) {
                    /* nothing typed yet - leave the rows empty */
                } else {
                    autoFill();
                }
                refresh();
            }).fail(function () {
                if (seq !== loadSeq) { return; }
                render();
                card.find('.apply-docs__empty').show().text('Could not load the unpaid ' + docWord + 's. You can still record the payment - it will be kept as ' + creditWord + '.');
                refresh();
            });
        };

        tbody.on('input change', 'input.apply-amt', function () {
            touched = true;
            if (mode === 'new' && !amountUser) {
                amountInput.val(appliedTotal() > 0 ? fromCents(appliedTotal()) : '');
            }
            refresh();
        });
        card.on('click', '.js-apply-auto', function (e) {
            e.preventDefault();
            touched = false;
            autoFill();
            refresh();
        });
        card.on('click', '.js-apply-clear', function (e) {
            e.preventDefault();
            touched = true;
            inputs().val('');
            if (mode === 'new' && !amountUser) { amountInput.val(''); }
            refresh();
        });

        if (mode === 'new') {
            amountUser = $.trim(amountInput.val()) !== '';
            amountInput.on('input change', function () {
                amountUser = $.trim(this.value) !== '';
                if (!touched) { autoFill(); }
                refresh();
            });
            partySelect.on('change', function () {
                focusDoc = 0;
                load();
            });
            load();
        } else {
            try { docs = JSON.parse(card.attr('data-docs') || '[]'); } catch (err) { docs = []; }
            render();
            autoFill();
            refresh();
        }
    });
});

/* =========================================================================
 * Credit Note / Supplier Credit form (Sept 2026) - views/credits/form.php.
 * Lines are built from the JSON in #creditFormData and posted as
 * lines[i][field]. Amounts here are only a preview: the server works
 * every amount out again (Credits_model::save()).
 * ========================================================================= */
$(document).ready(function () {
    var form = $('.js-credit-form');
    if (!form.length) { return; }
    var data;
    try { data = JSON.parse($('#creditFormData').text()); } catch (e) { return; }
    var isAr = data.side === 'ar';
    var dec = parseInt(data.decimals, 10);
    if (isNaN(dec) || dec < 0) { dec = 2; }
    var factor = Math.pow(10, dec);
    var catalog = data.catalog || {};
    var ledgers = data.ledgers || [];
    var tbody = form.find('table.credit-lines tbody');
    var counter = 0;

    var cents = function (v) { var n = parseFloat(String(v === undefined || v === null ? '' : v).replace(/,/g, '')); return isNaN(n) ? 0 : n; };
    var round = function (n) { return Math.round(n * factor) / factor; };
    var money = function (n) {
        var s = Math.abs(n).toFixed(dec).split('.');
        s[0] = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return (n < 0 ? '-' + (window.ND_CUR || '$') : (window.ND_CUR || '$')) + s.join('.');
    };
    var itemIds = Object.keys(catalog).sort(function (a, b) {
        return String(catalog[a].name).localeCompare(String(catalog[b].name));
    });

    var buildItemSelect = function (name, selected) {
        var sel = $('<select class="form-control form-control-sm js-cl-item">').attr('name', name);
        sel.append($('<option value="">').text('— none —'));
        $.each(itemIds, function (i, id) {
            var opt = $('<option>').attr('value', id).text(catalog[id].name + (catalog[id].type === 'inventory' ? ' (stock)' : ''));
            if (String(selected) === String(id)) { opt.prop('selected', true); }
            sel.append(opt);
        });
        return sel;
    };
    var buildLedgerSelect = function (name, selected) {
        var sel = $('<select class="form-control form-control-sm js-cl-ledger">').attr('name', name);
        sel.append($('<option value="">').text('Select an account'));
        $.each(ledgers, function (i, l) {
            var opt = $('<option>').attr('value', l[0]).text(l[1]);
            if (String(selected) === String(l[0])) { opt.prop('selected', true); }
            sel.append(opt);
        });
        return sel;
    };

    var syncInventory = function (tr) {
        var id = tr.find('.js-cl-item').val();
        var inv = !!(id && catalog[id] && catalog[id].type === 'inventory');
        var box = tr.find('.js-cl-restock');
        box.prop('disabled', !inv);
        if (!inv) { box.prop('checked', false); }
        tr.find('.js-cl-restock-na').toggle(!inv);
        box.toggle(inv);
        if (!isAr) {
            var led = tr.find('.js-cl-ledger');
            led.prop('disabled', inv).toggle(!inv);
            tr.find('.js-cl-ledger-inv').toggle(inv);
        }
    };

    var recalc = function () {
        var sub = 0;
        tbody.find('tr').each(function () {
            var tr = $(this);
            var amt = round(cents(tr.find('.js-cl-qty').val()) * cents(tr.find('.js-cl-price').val()));
            tr.find('.js-cl-amount').text(money(amt));
            sub += amt;
        });
        sub = round(sub);
        var pctRaw = $.trim(form.find('input[name="tax_percent"]').val());
        var tax = pctRaw !== '' ? round(sub * cents(pctRaw) / 100) : round(cents(form.find('input[name="tax_total"]').val()));
        form.find('input[name="tax_total"]').prop('disabled', pctRaw !== '');
        form.find('.js-credit-subtotal').text(money(sub));
        form.find('.js-credit-tax').text(money(tax));
        form.find('.js-credit-total').text(money(round(sub + tax)));
    };

    var addLine = function (l) {
        l = l || {};
        var i = counter++;
        var p = 'lines[' + i + ']';
        var tr = $('<tr>');
        var itemCell = $('<td>').append(buildItemSelect(p + '[item_id]', l.item_id || ''));
        if (l.source_line_id) { itemCell.append($('<input type="hidden">').attr('name', p + '[source_line_id]').val(l.source_line_id)); }
        tr.append(itemCell);
        tr.append($('<td>').append($('<input type="text" class="form-control form-control-sm js-cl-desc" maxlength="255">').attr('name', p + '[description]').val(l.description || '')));
        if (!isAr) {
            tr.append($('<td>').append(buildLedgerSelect(p + '[ledger_id]', l.ledger_id || ''))
                .append($('<small class="text-muted js-cl-ledger-inv" style="display:none;">Inventory Asset</small>')));
        }
        tr.append($('<td>').append($('<input type="number" step="any" min="0" class="form-control form-control-sm text-right js-cl-qty">').attr('name', p + '[qty]').val(l.qty !== undefined ? l.qty : '1')));
        tr.append($('<td>').append($('<input type="number" step="any" min="0" class="form-control form-control-sm text-right js-cl-price">').attr('name', p + '[unit_price]').val(l.unit_price !== undefined ? l.unit_price : '')));
        tr.append($('<td class="text-right js-cl-amount" style="vertical-align:middle;">').text(money(0)));
        var restock = $('<input type="checkbox" class="skip js-cl-restock" value="1">').attr('name', p + '[restock]').prop('checked', !!parseInt(l.restock || 0, 10));
        tr.append($('<td class="text-center" style="vertical-align:middle;">').append(restock).append($('<span class="text-muted js-cl-restock-na">—</span>')));
        tr.append($('<td style="vertical-align:middle;">').append($('<button type="button" class="btn btn-link btn-sm text-danger js-cl-remove" title="Remove line"><i class="fas fa-times"></i></button>')));
        tbody.append(tr);
        syncInventory(tr);
        return tr;
    };

    tbody.on('change', '.js-cl-item', function () {
        var tr = $(this).closest('tr');
        var it = catalog[this.value];
        if (it) {
            var desc = tr.find('.js-cl-desc');
            if ($.trim(desc.val()) === '' || desc.data('auto')) {
                desc.val(it.description || it.name).data('auto', true);
            }
            tr.find('.js-cl-price').val(it.price !== null && it.price !== undefined ? it.price : '');
            if (it.type === 'inventory') {
                tr.find('.js-cl-restock').prop('checked', true);
            } else if (!isAr && it.expense_ledger_id && !tr.find('.js-cl-ledger').val()) {
                tr.find('.js-cl-ledger').val(String(it.expense_ledger_id));
            }
        }
        syncInventory(tr);
        recalc();
    });
    tbody.on('input change', '.js-cl-qty, .js-cl-price', recalc);
    tbody.on('input', '.js-cl-desc', function () { $(this).data('auto', false); });
    tbody.on('click', '.js-cl-remove', function () {
        $(this).closest('tr').remove();
        if (!tbody.find('tr').length) { addLine(); }
        recalc();
    });
    form.on('click', '.js-credit-add-line', function () { addLine(); recalc(); });
    form.on('input change', 'input[name="tax_percent"], input[name="tax_total"]', recalc);

    var clicked = null;
    form.on('click', 'button[type="submit"]', function () { clicked = this; });
    form.on('submit', function (e) {
        var msg = clicked ? $(clicked).attr('data-issue-confirm') : null;
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });

    if (data.lines && data.lines.length) {
        $.each(data.lines, function (i, l) { addLine(l); });
    } else {
        addLine();
    }
    recalc();
});

/* =========================================================================
 * Stock Adjustment form (Sept 2026) - views/items/adjustment_add.php.
 * Lines posted as lines[i][item_id] / lines[i][new_qty]; the preview here
 * uses the quantity and average cost at page load, the server re-reads
 * both when posting (Stock_adjustments_model::create()).
 * ========================================================================= */
$(document).ready(function () {
    var form = $('.js-adjust-form');
    if (!form.length) { return; }
    var data;
    try { data = JSON.parse($('#adjustFormData').text()); } catch (e) { return; }
    var dec = parseInt(data.decimals, 10);
    if (isNaN(dec) || dec < 0) { dec = 2; }
    var factor = Math.pow(10, dec);
    var items = {};
    $.each(data.items || [], function (i, it) { items[it.id] = it; });
    var tbody = form.find('table.adjust-lines tbody');
    var counter = 0;
    var num = function (v) { var n = parseFloat(String(v === undefined || v === null ? '' : v).replace(/,/g, '')); return isNaN(n) ? 0 : n; };
    var round = function (n) { return Math.round(n * factor) / factor; };
    var money = function (n) {
        var s = Math.abs(n).toFixed(dec).split('.');
        s[0] = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return (n < 0 ? '-' + (window.ND_CUR || '$') : (window.ND_CUR || '$')) + s.join('.');
    };
    var qtyText = function (n) { return String(Math.round(n * 100) / 100); };

    var used = function () {
        var ids = {};
        tbody.find('.js-adj-item').each(function () { if (this.value) { ids[this.value] = true; } });
        return ids;
    };

    var addLine = function (itemId, newQty) {
        var i = counter++;
        var tr = $('<tr>');
        var sel = $('<select class="form-control form-control-sm js-adj-item">').attr('name', 'lines[' + i + '][item_id]');
        sel.append($('<option value="">').text('Select an item'));
        $.each(data.items || [], function (k, it) {
            var opt = $('<option>').attr('value', it.id).text(it.name);
            if (String(itemId) === String(it.id)) { opt.prop('selected', true); }
            sel.append(opt);
        });
        tr.append($('<td>').append(sel));
        tr.append($('<td class="text-right js-adj-now" style="vertical-align:middle;">').text('—'));
        tr.append($('<td>').append($('<input type="number" step="any" min="0" class="form-control form-control-sm text-right js-adj-new">').attr('name', 'lines[' + i + '][new_qty]').val(newQty !== undefined ? newQty : '')));
        tr.append($('<td class="text-right js-adj-change" style="vertical-align:middle;">').text('—'));
        tr.append($('<td class="text-right js-adj-cost" style="vertical-align:middle;">').text('—'));
        tr.append($('<td class="text-right js-adj-value" style="vertical-align:middle;">').text('—'));
        tr.append($('<td style="vertical-align:middle;">').append($('<button type="button" class="btn btn-link btn-sm text-danger js-adj-remove" title="Remove"><i class="fas fa-times"></i></button>')));
        tbody.append(tr);
        return tr;
    };

    var recalc = function () {
        var up = 0, down = 0;
        tbody.find('tr').each(function () {
            var tr = $(this);
            var it = items[tr.find('.js-adj-item').val()];
            var newRaw = $.trim(tr.find('.js-adj-new').val());
            if (!it) {
                tr.find('.js-adj-now, .js-adj-change, .js-adj-cost, .js-adj-value').text('—');
                return;
            }
            tr.find('.js-adj-now').text(qtyText(num(it.qty)));
            tr.find('.js-adj-cost').text(money(num(it.cost)));
            if (newRaw === '') {
                tr.find('.js-adj-change, .js-adj-value').text('—').removeClass('adj-up adj-down');
                return;
            }
            var change = Math.round((num(newRaw) - num(it.qty)) * 100) / 100;
            var value = round(change * num(it.cost));
            tr.find('.js-adj-change').text(change > 0 ? '+' + qtyText(change) : (change === 0 ? 'no change' : qtyText(change)))
                .toggleClass('adj-up', change > 0).toggleClass('adj-down', change < 0);
            tr.find('.js-adj-value').text(change === 0 ? '—' : money(value));
            if (value > 0) { up += value; } else { down += value; }
        });
        form.find('.js-adj-up').text(money(round(up)));
        form.find('.js-adj-down').text(money(round(down)));
        form.find('.js-adj-net').text(money(round(up + down)));
    };

    tbody.on('change', '.js-adj-item', function () {
        var dup = 0;
        var v = this.value;
        tbody.find('.js-adj-item').each(function () { if (this.value && this.value === v) { dup++; } });
        if (dup > 1) {
            window.alert('That item is already on another line.');
            this.value = '';
        }
        recalc();
    });
    tbody.on('input change', '.js-adj-new', recalc);
    tbody.on('click', '.js-adj-remove', function () {
        $(this).closest('tr').remove();
        if (!tbody.find('tr').length) { addLine(); }
        recalc();
    });
    form.on('click', '.js-adj-add', function () { addLine(); recalc(); });
    form.on('click', '.js-adj-all', function () {
        var have = used();
        tbody.find('tr').each(function () { if (!$(this).find('.js-adj-item').val()) { $(this).remove(); } });
        $.each(data.items || [], function (k, it) { if (!have[it.id]) { addLine(it.id, ''); } });
        recalc();
    });
    form.on('submit', function (e) {
        var changed = 0;
        tbody.find('tr').each(function () {
            var it = items[$(this).find('.js-adj-item').val()];
            var raw = $.trim($(this).find('.js-adj-new').val());
            if (it && raw !== '' && Math.round((num(raw) - num(it.qty)) * 100) !== 0) { changed++; }
        });
        if (!changed) {
            e.preventDefault();
            window.alert('Nothing to post - enter a new quantity that is different from what is on hand.');
            return;
        }
        if (!window.confirm('Post this adjustment for ' + changed + ' item' + (changed === 1 ? '' : 's') + '? Stock quantities and the ledger are updated straight away.')) {
            e.preventDefault();
        }
    });

    addLine(data.preselect || undefined);
    recalc();
});

/* QuickBooks-style reports (Sept 27 2026 - views/reports/*, libraries/
   Qb_report.php). Click a group heading to collapse the accounts under
   it - its total then shows on the heading line, like QuickBooks;
   "Collapse" / "Expand" does every group at once. A report with an
   amount on the unusual side (red, in brackets) shows the note that
   explains it. */
(function ($) {
    if (!$) { return; }
    function qbrApply($table) {
        var collapsed = $table.find('tr.qbr-toggle.qbr-is-collapsed').map(function () { return String($(this).data('gid')); }).get();
        $table.find('tr.qbr-row').each(function () {
            var path = ' ' + ($(this).attr('data-path') || '').trim() + ' ';
            var totalOf = $(this).attr('data-total-of');
            var hide = false;
            for (var i = 0; i < collapsed.length; i++) {
                if (path.indexOf(' ' + collapsed[i] + ' ') !== -1 || (totalOf && String(totalOf) === collapsed[i])) { hide = true; break; }
            }
            $(this).toggle(!hide);
        });
    }
    $(document).on('click', 'tr.qbr-toggle', function (e) {
        if ($(e.target).closest('a').length) { return; }
        $(this).toggleClass('qbr-is-collapsed');
        qbrApply($(this).closest('table'));
    });
    $(document).on('click', '.qbr-collapse-all', function (e) {
        e.preventDefault();
        var $b = $(this), open = $b.attr('data-state') !== 'closed';
        var $tables = $b.closest('.qbr-card').find('table.qbr-table');
        $tables.find('tr.qbr-toggle').toggleClass('qbr-is-collapsed', open);
        $tables.each(function () { qbrApply($(this)); });
        $b.attr('data-state', open ? 'closed' : 'open');
        $b.find('span').text(open ? 'Expand' : 'Collapse');
        $b.find('i').attr('class', open ? 'fas fa-expand-alt' : 'fas fa-compress-alt');
    });
    $(function () {
        if ($('.qbr-page tr.dc-error').length) { $('#reportDcNote').show(); }
    });
})(window.jQuery);

/* Journal entries: customers and vendors are not accounts (Sept 27 2026 -
   "when I'm recording in accounts like journal I don't want to see them").
   The account list (entries/ledgerList) shows "Accounts Receivable" /
   "Accounts Payable" once (value party:ar / party:ap) instead of every
   customer and vendor. Picking one opens a Name box on that line to
   choose the customer / vendor - QuickBooks style. The line still posts
   the customer's / vendor's own sub-account as Entryitem[..][ledger_id]
   (a hidden field), so saving works exactly as before. Existing lines
   that point at a customer / vendor are shown the same way. Needs
   window.NIDAAM_PARTIES (views/entries/_parties.php). */
(function ($) {
    if (!$) { return; }
    var P = function () { return window.NIDAAM_PARTIES || null; };
    var NAME_RE = /^(Entryitem\[[^\]]+\])\[ledger_id\]$/;

    function sideOf(ledgerId) {
        var p = P(); if (!p || !ledgerId) { return null; }
        var id = parseInt(ledgerId, 10);
        for (var side in p) {
            if (!p.hasOwnProperty(side)) { continue; }
            for (var i = 0; i < p[side].list.length; i++) { if (p[side].list[i][0] === id) { return side; } }
        }
        return null;
    }

    function showBalance($row, ledgerId) {
        var $cell = $row.find('.ledger-balance div, .ledger-balance').first();
        if (!$cell.length || !ledgerId || !window.site || !site.base_url) { return; }
        $.ajax({ url: site.base_url + 'ledgers/cl', data: 'id=' + ledgerId, dataType: 'json', success: function (d) {
            if (d && d.cl && (d.cl.dc === 'D' || d.cl.dc === 'C')) {
                var html = (d.cl.dc === 'D' ? 'Dr ' : 'Cr ') + parseFloat(d.cl.amount);
                $cell.html(d.cl.status === 'neg' ? '<span class="error-text">' + html + '</span>' : html);
            }
        } });
    }

    function fillNames($name, side, selected) {
        var p = P(); if (!p || !p[side]) { return; }
        $name.empty().append($('<option value="">').text('Choose the ' + p[side].who + '...'));
        $.each(p[side].list, function (i, it) {
            $name.append($('<option>').attr('value', it[0]).text(it[1]).prop('selected', String(it[0]) === String(selected)));
        });
        if ($.fn.select2) {
            if ($name.hasClass('select2-hidden-accessible')) { $name.select2('destroy'); }
            $name.select2({ width: '100%', placeholder: 'Choose the ' + p[side].who + '...' });
        }
    }

    function setup(select) {
        var $s = $(select);
        if ($s.data('ndParty') || !P()) { return; }
        var m = (select.getAttribute('name') || '').match(NAME_RE);
        if (!m) { return; }
        $s.data('ndParty', true);
        var current = $s.val();
        var $hidden = $('<input type="hidden">').attr('name', m[1] + '[ledger_id]').val(current || '');
        $s.attr('name', m[1] + '[account]');
        var $holder = $s.closest('.form-group-entryitem');
        if (!$holder.length) { $holder = $s; }
        var $wrap = $('<div class="nd-party-wrap" style="display:none; margin-top:4px;"><select class="form-control nd-party"></select><div class="nd-party-msg text-danger" style="display:none; font-size:12px;"></div></div>');
        $holder.after($wrap).after($hidden);
        $s.data('ndHidden', $hidden).data('ndWrap', $wrap);

        var side = sideOf(current);
        if (side) {
            var p = P();
            if (!$s.find('option[value="party:' + side + '"]').length) {
                $s.append($('<option>').attr('value', 'party:' + side).text(p[side].label));
            }
            $s.val('party:' + side).trigger('change.select2');
            fillNames($wrap.find('select'), side, current);
            $wrap.show();
        }
    }

    function scan(root) {
        $(root).find('select').each(function () {
            if (NAME_RE.test(this.getAttribute('name') || '')) { setup(this); }
        });
    }

    $(document).on('change', 'select', function () {
        var $s = $(this);
        if (!$s.data('ndParty')) { return; }
        var v = $s.val() || '', $hidden = $s.data('ndHidden'), $wrap = $s.data('ndWrap');
        if (v.indexOf('party:') === 0) {
            var side = v.substr(6);
            $wrap.show();
            $wrap.find('.nd-party-msg').hide();
            /* a re-fired change on the same account keeps the chosen name */
            if ($hidden.val() && sideOf($hidden.val()) === side) { return; }
            fillNames($wrap.find('select'), side, null);
            $hidden.val('');
        } else {
            $wrap.hide();
            $hidden.val(v);
        }
    });
    $(document).on('change', 'select.nd-party', function () {
        var $wrap = $(this).closest('.nd-party-wrap');
        var $hidden = $wrap.prevAll('input[type=hidden]').first();
        $hidden.val($(this).val() || '');
        $wrap.find('.nd-party-msg').hide();
        if ($(this).val()) { showBalance($wrap.closest('tr'), $(this).val()); }
    });
    /* A line on Accounts Receivable / Payable needs its customer / vendor. */
    $(document).on('submit', 'form', function (e) {
        var bad = null;
        $(this).find('.nd-party-wrap:visible').each(function () {
            if (!$(this).find('select.nd-party').val()) {
                var who = (P() && $(this).closest('td').find('select').first().val() === 'party:ap') ? 'vendor' : 'customer';
                $(this).find('.nd-party-msg').text('Choose the ' + who + ' for this line.').show();
                bad = bad || this;
            }
        });
        if (bad) { e.preventDefault(); e.stopImmediatePropagation(); bad.scrollIntoView({ block: 'center' }); }
    });

    $(function () {
        if (!P()) { return; }
        scan(document);
        if (window.MutationObserver) {
            new MutationObserver(function (muts) {
                muts.forEach(function (mu) { $.each(mu.addedNodes, function (i, n) { if (n.nodeType === 1) { scan(n); } }); });
            }).observe(document.body, { childList: true, subtree: true });
        }
    });
})(window.jQuery);
