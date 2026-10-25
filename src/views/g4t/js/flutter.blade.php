@verbatim
    /* ---------- Flutter: response / request models and a Dio repository method ---------- */

    var DART_RESERVED = ['abstract', 'as', 'assert', 'async', 'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'default', 'do', 'dynamic', 'else', 'enum', 'export', 'extends', 'external', 'factory', 'false', 'final', 'finally', 'for', 'get', 'if', 'implements', 'import', 'in', 'is', 'library', 'new', 'null', 'operator', 'part', 'rethrow', 'return', 'set', 'static', 'super', 'switch', 'this', 'throw', 'true', 'try', 'typedef', 'var', 'void', 'while', 'with', 'yield'];
    function words(text) { return String(text).replace(/([a-z0-9])([A-Z])/g, '$1 $2').split(/[^A-Za-z0-9]+/).filter(Boolean); }
    function pascal(text) { return words(text).map(function (w) { return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase(); }).join('') || 'Model'; }
    function camel(text) { var p = pascal(text); var name = p.charAt(0).toLowerCase() + p.slice(1); if (/^\d/.test(name)) { name = 'v' + name; } return DART_RESERVED.indexOf(name) !== -1 ? name + '_' : name; }
    function singular(name) { return /ies$/i.test(name) ? name.replace(/ies$/i, 'y') : /ses$/i.test(name) ? name.replace(/es$/i, '') : /s$/i.test(name) ? name.slice(0, -1) : name + 'Item'; }

    /** Dart classes for a schema; returns the Dart type of the root. Each named component becomes one class. */
    function dartTypes(raw, hint, ctx, depth) {
        var r = resolve(raw), s = r.schema, type = schemaType(s);
        if (isBinary(raw)) { return 'MultipartFile'; }
        if (type === 'array') { return 'List<' + dartTypes(s.items || {}, singular(hint), ctx, depth + 1) + '>'; }
        if (type === 'object' && s.properties && Object.keys(s.properties).length && depth < 6) {
            var name = pascal(r.name ? r.name.replace(/Resource$/, '') : hint);
            if (ctx.names[name] === r.name || ctx.names[name] === hint + ':' + depth) { return name; }
            while (ctx.names[name] !== undefined) { name += '_'; }
            ctx.names[name] = r.name || hint + ':' + depth;
            var required = s.required || [], fields = Object.keys(s.properties).map(function (key) {
                var prop = s.properties[key], ps = resolve(prop).schema;
                var fieldType = dartTypes(prop, pascal(key), ctx, depth + 1);
                return { key: key, name: camel(key), type: fieldType, optional: ctx.request ? (required.indexOf(key) === -1 || !!ps.nullable) : true };
            });
            ctx.classes.push({ name: name, fields: fields });
            return name;
        }
        if (type === 'object') { return 'Map<String, dynamic>'; }
        return { integer: 'int', number: 'double', boolean: 'bool', string: 'String' }[type] || 'dynamic';
    }
    function isModelType(type) { return /^[A-Z]/.test(type) && ['String', 'List', 'Map', 'MultipartFile'].indexOf(type.split('<')[0]) === -1; }
    function dartFrom(type, expr, optional) {
        var q = optional ? '?' : '';
        if (type === 'int') { return '(' + expr + ' as num' + q + ')' + q + '.toInt()'; }
        if (type === 'double') { return '(' + expr + ' as num' + q + ')' + q + '.toDouble()'; }
        if (type === 'bool' || type === 'String' || type === 'Map<String, dynamic>') { return expr + ' as ' + type + q; }
        if (/^List</.test(type)) {
            var item = type.slice(5, -1);
            return '(' + expr + ' as List' + q + ')' + q + '.map((e) => ' + dartFrom(item, 'e', false) + ').toList()';
        }
        if (isModelType(type)) { return (optional ? expr + ' == null ? null : ' : '') + type + '.fromJson(' + expr + ' as Map<String, dynamic>)'; }
        return expr;
    }
    function dartTo(type, expr, optional) {
        var q = optional ? '?' : '';
        if (/^List</.test(type)) {
            var item = type.slice(5, -1);
            return isModelType(item) || /^List</.test(item) ? expr + q + '.map((e) => ' + dartTo(item, 'e', false) + ').toList()' : expr;
        }
        return isModelType(type) ? expr + q + '.toJson()' : expr;
    }
    function dartClassCode(c) {
        var lines = ['class ' + c.name + ' {', '  const ' + c.name + '({'];
        c.fields.forEach(function (f) { lines.push('    ' + (f.optional ? '' : 'required ') + 'this.' + f.name + ','); });
        lines.push('  });', '');
        c.fields.forEach(function (f) { lines.push('  final ' + f.type + (f.optional && f.type !== 'dynamic' ? '?' : '') + ' ' + f.name + ';'); });
        lines.push('', '  factory ' + c.name + '.fromJson(Map<String, dynamic> json) => ' + c.name + '(');
        c.fields.forEach(function (f) { lines.push('        ' + f.name + ': ' + dartFrom(f.type, "json['" + f.key + "']", f.optional) + ','); });
        lines.push('      );', '', '  Map<String, dynamic> toJson() => {');
        c.fields.forEach(function (f) { lines.push("        '" + f.key + "': " + dartTo(f.type, f.name, f.optional) + ','); });
        lines.push('      };', '}');
        return lines.join('\n');
    }
    function dartMethodName(op) {
        var summaryWords = words(op.summary || '');
        return summaryWords.length && summaryWords.length <= 4 ? camel(op.summary) : camel(op.method.toLowerCase() + ' ' + op.path.replace(/\/api\//, '/').replace(/\/v\d+\//, '/').replace(/[{}?]/g, ''));
    }
    function codeFlutter(op) {
        var method = dartMethodName(op), base = pascal(method), classes = [];
        var success = op.responses.filter(function (r) { return typeof r.code === 'number' && r.code < 300 && r.schema; })[0];
        var resCtx = { names: {}, classes: [], request: false };
        var resType = success ? dartTypes(success.schema, base + 'Response', resCtx, 0) : 'void';
        classes = classes.concat(resCtx.classes.reverse());
        var body = op.body && ['GET', 'HEAD'].indexOf(op.method) === -1 ? op.body : null;
        var ctype = body ? (body.content['application/json'] ? 'application/json' : op.ctypes[0]) : null;
        var reqCtx = { names: resCtx.names, classes: [], request: true };
        var reqType = body ? dartTypes(body.content[ctype].schema || {}, base + 'Request', reqCtx, 0) : null;
        classes = reqCtx.classes.reverse().concat(classes);

        var pathParams = op.params.filter(function (p) { return p.in === 'path'; });
        var queryParams = op.params.filter(function (p) { return p.in === 'query'; });
        var args = pathParams.map(function (p) { return dartTypes(p.schema || {}, p.name, { names: {}, classes: [] }, 9) + ' ' + camel(p.name); });
        if (reqType) { args.push(reqType + ' body'); }
        var named = queryParams.map(function (p) { return dartTypes(p.schema || {}, p.name, { names: {}, classes: [] }, 9) + '? ' + camel(p.name); });
        var signature = args.join(', ') + (named.length ? (args.length ? ', ' : '') + '{' + named.join(', ') + '}' : '');
        var path = op.path.replace(/\{(\w+)\??\}/g, function (m, name) { return '$' + camel(name); });
        var multipart = ctype && /multipart/.test(ctype);
        var call = ['    ' + (resType === 'void' ? '' : 'final response = ') + 'await _dio.' + op.method.toLowerCase() + "<dynamic>(", "      '" + path + "',"];
        if (reqType) { call.push('      data: ' + (multipart ? 'FormData.fromMap(body.toJson())' : 'body.toJson()') + ','); }
        if (queryParams.length) {
            call.push('      queryParameters: {');
            queryParams.forEach(function (p) { call.push("        if (" + camel(p.name) + " != null) '" + p.name + "': " + camel(p.name) + ','); });
            call.push('      },');
        }
        call.push('    );');
        var ret = resType === 'void' ? [] : /^List</.test(resType) ? ['    return ' + dartFrom(resType, 'response.data', false) + ';'] : isModelType(resType) ? ['    return ' + resType + '.fromJson(response.data as Map<String, dynamic>);'] : ['    return response.data as ' + resType + ';'];
        var repo = pascal(op.tag.replace(/^v\d+\//i, '')) + 'Repository';
        var header = [
            '// ' + op.method + ' ' + op.path + (op.summary ? ' · ' + op.summary : ''),
            '// Generated by g4t/swagger. Needs dio: ^5.0.0',
            "import 'package:dio/dio.dart';",
            '',
            '// final dio = Dio(BaseOptions(baseUrl: ' + "'" + serverUrl() + "'" + ", headers: {'Accept': 'application/json'}));" + (op.secured ? "\n// dio.options.headers['Authorization'] = 'Bearer $token';" : ''),
            ''
        ];
        var repoCode = ['class ' + repo + ' {', '  ' + repo + '(this._dio);', '', '  final Dio _dio;', '', '  Future<' + resType + '> ' + method + '(' + signature + ') async {'].concat(call, ret, ['  }', '}']);
        return header.concat(classes.map(dartClassCode).join('\n\n'), classes.length ? [''] : [], repoCode).join('\n');
    }
@endverbatim
