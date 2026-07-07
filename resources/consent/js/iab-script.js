	var n, s, e, i, o, r, a, p = {},
		l = [],
		d = /acit|ex(?:s|g|n|p|$)|rph|grid|ows|mnc|ntw|ine[ch]|zoo|^ord|itera/i,
		c = Array.isArray;

		function t(t) {
		return t && t.__esModule ? t.default : t
	}
	function u(t, e) {
		for (var n in e) t[n] = e[n];
		return t
	}
	function k2(e) {
		return Object.keys(e).length
	}
	function _(t) {
		var e = t.parentNode;
		e && e.removeChild(t)
	}

	function m(t, n, s) {
		var r, o, i, a = {};
		for (i in n) "key" == i ? r = n[i] : "ref" == i ? o = n[i] : a[i] = n[i];
		if (arguments.length > 2 && (a.children = arguments.length > 3 ? e.call(arguments, 2) : s), "function" == typeof t && null != t.defaultProps)
			for (i in t.defaultProps) void 0 === a[i] && (a[i] = t.defaultProps[i]);
		return h(t, a, r, o, null)
	}
	function g(t, e) {
		this.props = t, this.context = e
	}
	var nt = "function" == typeof requestAnimationFrame;

	function st(t) {
		var e, n = function() {
				clearTimeout(s), nt && cancelAnimationFrame(e), setTimeout(t)
			},
			s = setTimeout(n, 100);
		nt && (e = requestAnimationFrame(n))
	}

	function _et() {
				var e = {
					sectionChecked: !1,
					consent: {
						allowed: [],
						rejected: []
					},
					legitimateInterest: {
						allowed: [],
						rejected: []
					}
				};
				cz._tcModal.vendorConsents.forEach((function(t, n) {
					t ? e.consent.allowed.push(n) : e.consent.rejected.push(n)
				})), cz._tcModal.vendorLegitimateInterests.forEach((function(t, n) {
					t ? e.legitimateInterest.allowed.push(n) : e.legitimateInterest.rejected.push(n)
				}));
				for (var t = [], n = [], r = 0, o = Object.values(cz._tcModal.gvl.vendors); r < o.length; r++) {
					var i = o[r];
					0 !== i.purposes.length && t.push(i.id), 0 !== i.legIntPurposes.length && n.push(i.id)
				}
				return e.sectionChecked = e.consent.allowed.length >= t.length && e.legitimateInterest.allowed.length >= n.length, e
			}

			function k(e, n, r) {
						var o = p;
						return function(i, c) {
							if (o === v) throw new Error("Generator is already running");
							if (o === h) {
								if ("throw" === i) throw c;
								return {
									value: t,
									done: !0
								}
							}
							for (r.method = i, r.arg = c;;) {
								var s = r.delegate;
								if (s) {
									var a = I(s, r);
									if (a) {
										if (a === y) continue;
										return a
									}
								}
								if ("next" === r.method) r.sent = r._sent = r.arg;
								else if ("throw" === r.method) {
									if (o === p) throw o = h, r.arg;
									r.dispatchException(r.arg)
								} else "return" === r.method && r.abrupt("return", r.arg);
								o = v;
								var u = f(e, n, r);
								if ("normal" === u.type) {
									if (o = r.done ? h : d, u.arg === y) continue;
									return {
										value: u.arg,
										done: r.done
									}
								}
								"throw" === u.type && (o = h, r.method = "throw", r.arg = u.arg)
							}
						}
					}
			function _tt() {
				var e = {
						sectionChecked: !1,
						consent: {
							allowed: [],
							rejected: []
						}
					},
					t = {};
				return cz._addtlConsent && cz._addtlConsent.split("~")[1].split(".").forEach((function(e) {
					return t[e] = !0
				})), Object.keys(cz._tcModal.gvl.googleVendors).forEach((function(n) {
					t[n] ? e.consent.allowed.push(n) : e.consent.rejected.push(n)
				})), e.sectionChecked = e.consent.allowed.length === k2(cz._tcModal.gvl.googleVendors), e
			}

			function _nt() {
				var e = {
					consent: {
						allowed: [],
						rejected: []
					},
					legitimateInterest: {
						allowed: [],
						rejected: []
					},
					sectionChecked: !1
				};
				return cz._tcModal.purposeConsents.forEach((function(t, n) {
					t ? e.consent.allowed.push(n) : e.consent.rejected.push(n)
				})), cz._tcModal.purposeLegitimateInterests.forEach((function(t, n) {
					t ? e.legitimateInterest.allowed.push(n) : e.legitimateInterest.rejected.push(n)
				})), e.sectionChecked = e.consent.allowed.length === k2(cz._tcModal.gvl.purposes), e
			}

			function _rt() {
				var e = {
					consent: {
						allowed: [],
						rejected: []
					},
					sectionChecked: !1
				};
				return cz._tcModal.specialFeatureOptins.forEach((function(t, n) {
					t ? e.consent.allowed.push(n) : e.consent.rejected.push(n)
				})), e.sectionChecked = e.consent.allowed.length === k2(cz._tcModal.gvl.specialFeatures), e
			}

			function ot(e, t) {
				var n = arguments.length > 2 && void 0 !== arguments[2] ? arguments[2] : null;
				return function(r) {
					var o = r.id,
						i = r.name,
						c = r.description,
						s = r.illustrations,
						a = n;
					a = null === a ? ![1, 3, 4, 5, 6].includes(o) : a;
					var u = 0;
					return u = 1 === e ? k2(cz._tcModal.gvl.getVendorsWithConsentPurpose(o)) + k2(cz._tcModal.gvl.getVendorsWithLegIntPurpose(o)) : k2(2 === e ? cz._tcModal.gvl.getVendorsWithSpecialPurpose(o) : 3 === e ? cz._tcModal.gvl.getVendorsWithFeature(o) : cz._tcModal.gvl.getVendorsWithSpecialFeature(o)), {
						id: o,
						name: i,
						userFriendlyText: c,
						hasConsentToggle: t,
						hasLegitimateToggle: a,
						illustrations: s.filter((function(e) {
							return e
						})),
						combinedSeeker: a,
						seekerCount: u
					}
				}
			}

			function it(e, t, n) {
				return Object.values(e).filter((function(e) {
					return t.includes(e.id)
				})).map((function(e) {
					var t = {
						name: e.name
					};
					return n && (t[n] = n.purposes[e.id] || 0), t
				}))
			}

	function at(t, e) {
		return "function" == typeof e ? e(t) : e
	}

	function pt(t, e) {
		for (var n in e) t[n] = e[n];
		return t
	}

	function lt(t, e) {
		for (var n in t)
			if ("__source" !== n && !(n in e)) return !0;
		for (var s in e)
			if ("__source" !== s && t[s] !== e[s]) return !0;
		return !1
	}

	function dt(t) {
		this.props = t
	}(dt.prototype = new g).isPureReactComponent = !0, dt.prototype.shouldComponentUpdate = function(t, e) {
		return lt(this.props, t) || lt(this.state, e)
	};

	function mt(t, e, n) {
		return t && (t.__c && t.__c.__H && (t.__c.__H.__.forEach((function(t) {
			"function" == typeof t.__c && t.__c()
		})), t.__c.__H = null), null != (t = pt({}, t)).__c && (t.__c.__P === n && (t.__c.__P = e), t.__c = null), t.__k = t.__k && t.__k.map((function(t) {
			return mt(t, e, n)
		}))), t
	}

	function ht(t, e, n) {
		return t && (t.__v = null, t.__k = t.__k && t.__k.map((function(t) {
			return ht(t, e, n)
		})), t.__c && t.__c.__P === e && (t.__e && n.insertBefore(t.__e, t.__d), t.__c.__e = !0, t.__c.__P = n)), t
	}

	function ft() {
		this.__u = 0, this.t = null, this.__b = null
	}

	function gt(t) {
		var e = t.__.__c;
		return e && e.__a && e.__a(t)
	}

	function bt() {
		this.u = null, this.o = null
	}
	const Jt = {
			purposes: "purposes",
			landingScreen: "landingScreen",
			vendors: "vendors"
		};
	   
	var oe, ie, ae, pe, le, de, ce, ue, _e, me, he, fe, ge, be, ve, ye;
	(ie = oe || (oe = {})).PING = "ping", ie.GET_TC_DATA = "getTCData", ie.GET_IN_APP_TC_DATA = "getInAppTCData", ie.GET_VENDOR_LIST = "getVendorList", ie.ADD_EVENT_LISTENER = "addEventListener", ie.REMOVE_EVENT_LISTENER = "removeEventListener", (pe = ae || (ae = {})).STUB = "stub", pe.LOADING = "loading", pe.LOADED = "loaded", pe.ERROR = "error", (de = le || (le = {})).VISIBLE = "visible", de.HIDDEN = "hidden", de.DISABLED = "disabled", (ue = ce || (ce = {})).TC_LOADED = "tcloaded", ue.CMP_UI_SHOWN = "cmpuishown", ue.USER_ACTION_COMPLETE = "useractioncomplete";
	class Se {
		listenerId;
		callback;
		next;
		param;
		success = !0;
		constructor(t, e, n, s) {
			Object.assign(this, {
				callback: t,
				listenerId: n,
				param: e,
				next: s
			});
			try {
				this.respond()
			} catch (t) {
				this.invokeCallback(null)
			}
		}
		invokeCallback(t) {
			const e = null !== t;
			"function" == typeof this.next ? this.callback(this.next, t, e) : this.callback(t, e)
		}
	}
	class Ee extends Se {
		respond() {
			this.throwIfParamInvalid(), this.invokeCallback(new Ve(this.param, this.listenerId))
		}
		throwIfParamInvalid() {
			if (!(void 0 === this.param || Array.isArray(this.param) && this.param.every(Number.isInteger))) throw new Error("Invalid Parameter")
		}
	}
	class Ie {
		eventQueue = new Map;
		queueNumber = 0;
		add(t) {
			return this.eventQueue.set(this.queueNumber, t), this.queueNumber++
		}
		remove(t) {
			return this.eventQueue.delete(t)
		}
		exec() {
			this.eventQueue.forEach(((t, e) => {
				new Ee(t.callback, t.param, e, t.next)
			}))
		}
		clear() {
			this.queueNumber = 0, this.eventQueue.clear()
		}
		get size() {
			return this.eventQueue.size
		}
	}
	class ke {
		static apiVersion = "2";
		static tcfPolicyVersion;
		static eventQueue = new Ie;
		static cmpStatus = ae.LOADING;
		static disabled = !1;
		static displayStatus = le.HIDDEN;
		static cmpId;
		static cmpVersion;
		static eventStatus;
		static gdprApplies;
		static tcModel;
		static tcString;
		static reset() {
			delete this.cmpId, delete this.cmpVersion, delete this.eventStatus, delete this.gdprApplies, delete this.tcModel, delete this.tcString, delete this.tcfPolicyVersion, this.cmpStatus = ae.LOADING, this.disabled = !1, this.displayStatus = le.HIDDEN, this.eventQueue.clear()
		}
	}
	class Le {
		cmpId = ke.cmpId;
		cmpVersion = ke.cmpVersion;
		gdprApplies = ke.gdprApplies;
		tcfPolicyVersion = ke.tcfPolicyVersion
	}
	class Te extends Le {
		cmpStatus = ae.ERROR
	}
	class Ve extends Le {
		tcString;
		listenerId;
		eventStatus;
		cmpStatus;
		isServiceSpecific;
		useNonStandardTexts;
		publisherCC;
		purposeOneTreatment;
		outOfBand;
		purpose;
		vendor;
		specialFeatureOptins;
		publisher;
		constructor(t, e) {
			if (super(), this.eventStatus = ke.eventStatus, this.cmpStatus = ke.cmpStatus, this.listenerId = e, ke.gdprApplies) {
				const e = ke.tcModel;
				this.tcString = ke.tcString, this.isServiceSpecific = e.isServiceSpecific, this.useNonStandardTexts = e.useNonStandardTexts, this.purposeOneTreatment = e.purposeOneTreatment, this.publisherCC = e.publisherCountryCode, this.outOfBand = {
					allowedVendors: this.createVectorField(e.vendorsAllowed, t),
					disclosedVendors: this.createVectorField(e.vendorsDisclosed, t)
				}, this.purpose = {
					consents: this.createVectorField(e.purposeConsents),
					legitimateInterests: this.createVectorField(e.purposeLegitimateInterests)
				}, this.vendor = {
					consents: this.createVectorField(e.vendorConsents, t),
					legitimateInterests: this.createVectorField(e.vendorLegitimateInterests, t),
					disclosedVendors: this.createVectorField(e.vendorsDisclosed, t)
				}, this.specialFeatureOptins = this.createVectorField(e.specialFeatureOptins), this.publisher = {
					consents: this.createVectorField(e.publisherConsents),
					legitimateInterests: this.createVectorField(e.publisherLegitimateInterests),
					customPurpose: {
						consents: this.createVectorField(e.publisherCustomConsents),
						legitimateInterests: this.createVectorField(e.publisherCustomLegitimateInterests)
					},
					restrictions: this.createRestrictions(e.publisherRestrictions)
				}
			}
		}
		createRestrictions(t) {
			const e = {};
			if (t.numRestrictions > 0) {
				const n = t.getMaxVendorId();
				for (let s = 1; s <= n; s++) {
					const n = s.toString();
					t.getRestrictions(s).forEach((t => {
						const s = t.purposeId.toString();
						e[s] || (e[s] = {}), e[s][n] = t.restrictionType
					}))
				}
			}
			return e
		}
		createVectorField(t, e) {
			return e ? e.reduce(((e, n) => (e[String(n)] = t.has(Number(n)), e)), {}) : [...t].reduce(((t, e) => (t[e[0].toString(10)] = e[1], t)), {})
		}
	}
	class Oe extends Ve {
		constructor(t) {
			super(t), delete this.outOfBand
		}
		createVectorField(t) {
			return [...t].reduce(((t, e) => t += e[1] ? "1" : "0"), "")
		}
		createRestrictions(t) {
			const e = {};
			if (t.numRestrictions > 0) {
				const n = t.getMaxVendorId();
				t.getRestrictions().forEach((t => {
					e[t.purposeId.toString()] = "_".repeat(n)
				}));
				for (let s = 0; s < n; s++) {
					const n = s + 1;
					t.getRestrictions(n).forEach((t => {
						const n = t.restrictionType.toString(),
							r = t.purposeId.toString(),
							o = e[r].substr(0, s),
							i = e[r].substr(s + 1);
						e[r] = o + n + i
					}))
				}
			}
			return e
		}
	}
	class Pe extends Le {
		cmpLoaded = !0;
		cmpStatus = ke.cmpStatus;
		displayStatus = ke.displayStatus;
		apiVersion = String(ke.apiVersion);
		gvlVersion;
		constructor() {
			super(), ke.tcModel && ke.tcModel.vendorListVersion && (this.gvlVersion = +ke.tcModel.vendorListVersion)
		}
	}
	class Ae extends Se {
		respond() {
			this.invokeCallback(new Pe)
		}
	}
	class Ne extends Ee {
		respond() {
			this.throwIfParamInvalid(), this.invokeCallback(new Oe(this.param))
		}
	}
	class Re extends Error {
		constructor(t) {
			super(t), this.name = "DecodingError"
		}
	}
	class De extends Error {
		constructor(t) {
			super(t), this.name = "EncodingError"
		}
	}
	class Fe extends Error {
		constructor(t) {
			super(t), this.name = "GVLError"
		}
	}
	class Me extends Error {
		constructor(t, e, n = "") {
			super(`invalid value ${e} passed for ${t} ${n}`), this.name = "TCModelError"
		}
	}
	class Ue {
		static DICT = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";
		static REVERSE_DICT = new Map([
			["A", 0],
			["B", 1],
			["C", 2],
			["D", 3],
			["E", 4],
			["F", 5],
			["G", 6],
			["H", 7],
			["I", 8],
			["J", 9],
			["K", 10],
			["L", 11],
			["M", 12],
			["N", 13],
			["O", 14],
			["P", 15],
			["Q", 16],
			["R", 17],
			["S", 18],
			["T", 19],
			["U", 20],
			["V", 21],
			["W", 22],
			["X", 23],
			["Y", 24],
			["Z", 25],
			["a", 26],
			["b", 27],
			["c", 28],
			["d", 29],
			["e", 30],
			["f", 31],
			["g", 32],
			["h", 33],
			["i", 34],
			["j", 35],
			["k", 36],
			["l", 37],
			["m", 38],
			["n", 39],
			["o", 40],
			["p", 41],
			["q", 42],
			["r", 43],
			["s", 44],
			["t", 45],
			["u", 46],
			["v", 47],
			["w", 48],
			["x", 49],
			["y", 50],
			["z", 51],
			["0", 52],
			["1", 53],
			["2", 54],
			["3", 55],
			["4", 56],
			["5", 57],
			["6", 58],
			["7", 59],
			["8", 60],
			["9", 61],
			["-", 62],
			["_", 63]
		]);
		static BASIS = 6;
		static LCM = 24;
		static encode(t) {
			if (!/^[0-1]+$/.test(t)) throw new De("Invalid bitField");
			const e = t.length % this.LCM;
			t += e ? "0".repeat(this.LCM - e) : "";
			let n = "";
			for (let e = 0; e < t.length; e += this.BASIS) n += this.DICT[parseInt(t.substr(e, this.BASIS), 2)];
			return n
		}
		static decode(t) {
			if (!/^[A-Za-z0-9\-_]+$/.test(t)) throw new Re("Invalidly encoded Base64URL string");
			let e = "";
			for (let n = 0; n < t.length; n++) {
				const s = this.REVERSE_DICT.get(t[n]).toString(2);
				e += "0".repeat(this.BASIS - s.length) + s
			}
			return e
		}
	}
	class je {
		static langSet = new Set(["AR", "BG", "BS", "CA", "CS", "DA", "DE", "EL", "EN", "ES", "ET", "EU", "FI", "FR", "GL", "HR", "HU", "IT", "JA", "LT", "LV", "MT", "NL", "NO", "PL", "PT-BR", "PT-PT", "RO", "RU", "SK", "SL", "SR-LATN", "SR-CYRL", "SV", "TR", "ZH"]);
		has(t) {
			return je.langSet.has(t)
		}
		parseLanguage(t) {
			const e = (t = t.toUpperCase()).split("-")[0];
			if (t.length >= 2 && 2 == e.length) {
				if (je.langSet.has(t)) return t;
				if (je.langSet.has(e)) return e;
				const n = e + "-" + e;
				if (je.langSet.has(n)) return n;
				for (const n of je.langSet)
					if (-1 !== n.indexOf(t) || -1 !== n.indexOf(e)) return n
			}
			throw new Error(`unsupported language ${t}`)
		}
		forEach(t) {
			je.langSet.forEach(t)
		}
		get size() {
			return je.langSet.size
		}
	}
	class He {
		static cmpId = "cmpId";
		static cmpVersion = "cmpVersion";
		static consentLanguage = "consentLanguage";
		static consentScreen = "consentScreen";
		static created = "created";
		static supportOOB = "supportOOB";
		static isServiceSpecific = "isServiceSpecific";
		static lastUpdated = "lastUpdated";
		static numCustomPurposes = "numCustomPurposes";
		static policyVersion = "policyVersion";
		static publisherCountryCode = "publisherCountryCode";
		static publisherCustomConsents = "publisherCustomConsents";
		static publisherCustomLegitimateInterests = "publisherCustomLegitimateInterests";
		static publisherLegitimateInterests = "publisherLegitimateInterests";
		static publisherConsents = "publisherConsents";
		static publisherRestrictions = "publisherRestrictions";
		static purposeConsents = "purposeConsents";
		static purposeLegitimateInterests = "purposeLegitimateInterests";
		static purposeOneTreatment = "purposeOneTreatment";
		static specialFeatureOptins = "specialFeatureOptins";
		static useNonStandardTexts = "useNonStandardTexts";
		static vendorConsents = "vendorConsents";
		static vendorLegitimateInterests = "vendorLegitimateInterests";
		static vendorListVersion = "vendorListVersion";
		static vendorsAllowed = "vendorsAllowed";
		static vendorsDisclosed = "vendorsDisclosed";
		static version = "version"
	}
	class ze {
		clone() {
			const t = new this.constructor;
			return Object.keys(this).forEach((e => {
				const n = this.deepClone(this[e]);
				void 0 !== n && (t[e] = n)
			})), t
		}
		deepClone(t) {
			const e = typeof t;
			if ("number" === e || "string" === e || "boolean" === e) return t;
			if (null !== t && "object" === e) {
				if ("function" == typeof t.clone) return t.clone();
				if (t instanceof Date) return new Date(t.getTime());
				if (void 0 !== t[Symbol.iterator]) {
					const e = [];
					for (const n of t) e.push(this.deepClone(n));
					return t instanceof Array ? e : new t.constructor(e)
				} {
					const e = {};
					for (const n in t) t.hasOwnProperty(n) && (e[n] = this.deepClone(t[n]));
					return e
				}
			}
		}
	}(me = _e || (_e = {}))[me.NOT_ALLOWED = 0] = "NOT_ALLOWED", me[me.REQUIRE_CONSENT = 1] = "REQUIRE_CONSENT", me[me.REQUIRE_LI = 2] = "REQUIRE_LI";
	class Ge extends ze {
		static hashSeparator = "-";
		purposeId_;
		restrictionType;
		constructor(t, e) {
			super(), void 0 !== t && (this.purposeId = t), void 0 !== e && (this.restrictionType = e)
		}
		static unHash(t) {
			const e = t.split(this.hashSeparator),
				n = new Ge;
			if (2 !== e.length) throw new Me("hash", t);
			return n.purposeId = parseInt(e[0], 10), n.restrictionType = parseInt(e[1], 10), n
		}
		get hash() {
			if (!this.isValid()) throw new Error("cannot hash invalid PurposeRestriction");
			return `${this.purposeId}${Ge.hashSeparator}${this.restrictionType}`
		}
		get purposeId() {
			return this.purposeId_
		}
		set purposeId(t) {
			this.purposeId_ = t
		}
		isValid() {
			return Number.isInteger(this.purposeId) && this.purposeId > 0 && (this.restrictionType === _e.NOT_ALLOWED || this.restrictionType === _e.REQUIRE_CONSENT || this.restrictionType === _e.REQUIRE_LI)
		}
		isSameAs(t) {
			return this.purposeId === t.purposeId && this.restrictionType === t.restrictionType
		}
	}
	class Be extends ze {
		bitLength = 0;
		map = new Map;
		gvl_;
		has(t) {
			return this.map.has(t)
		}
		isOkToHave(t, e, n) {
			let s = !0;
			if (this.gvl?.vendors) {
				const r = this.gvl.vendors[n];
				if (r)
					if (t === _e.NOT_ALLOWED) s = r.legIntPurposes.includes(e) || r.purposes.includes(e);
					else if (r.flexiblePurposes.length) switch (t) {
					case _e.REQUIRE_CONSENT:
						s = r.flexiblePurposes.includes(e) && r.legIntPurposes.includes(e);
						break;
					case _e.REQUIRE_LI:
						s = r.flexiblePurposes.includes(e) && r.purposes.includes(e)
				} else s = !1;
				else s = !1
			}
			return s
		}
		add(t, e) {
			if (this.isOkToHave(e.restrictionType, e.purposeId, t)) {
				const n = e.hash;
				this.has(n) || (this.map.set(n, new Set), this.bitLength = 0), this.map.get(n).add(t)
			}
		}
		restrictPurposeToLegalBasis(t, e = Array.from(this.gvl.vendorIds)) {
			const n = t.hash;
			if (this.has(n)) {
				const t = this.map.get(n);
				for (const n of e) t.add(n)
			} else this.map.set(n, new Set(e)), this.bitLength = 0
		}
		getVendors(t) {
			let e = [];
			if (t) {
				const n = t.hash;
				this.has(n) && (e = Array.from(this.map.get(n)))
			} else {
				const t = new Set;
				this.map.forEach((e => {
					Array.from(e).forEach((e => {
						t.add(e)
					}))
				})), e = Array.from(t)
			}
			return e.sort(((t, e) => t - e))
		}
		getRestrictionType(t, e) {
			let n;
			return this.getRestrictions(t).forEach((t => {
				t.purposeId === e && (void 0 === n || n > t.restrictionType) && (n = t.restrictionType)
			})), n
		}
		vendorHasRestriction(t, e) {
			let n = !1;
			const s = this.getRestrictions(t);
			for (let t = 0; t < s.length && !n; t++) n = e.isSameAs(s[t]);
			return n
		}
		getMaxVendorId() {
			let t = 0;
			return this.map.forEach((e => {
				const n = Array.from(e);
				t = Math.max(n[n.length - 1], t)
			})), t
		}
		getRestrictions(t) {
			const e = [];
			return this.map.forEach(((n, s) => {
				t ? n.has(t) && e.push(Ge.unHash(s)) : e.push(Ge.unHash(s))
			})), e
		}
		getPurposes() {
			const t = new Set;
			return this.map.forEach(((e, n) => {
				t.add(Ge.unHash(n).purposeId)
			})), Array.from(t)
		}
		remove(t, e) {
			const n = e.hash,
				s = this.map.get(n);
			s && (s.delete(t), 0 == s.size && (this.map.delete(n), this.bitLength = 0))
		}
		set gvl(t) {
			this.gvl_ || (this.gvl_ = t, this.map.forEach(((t, e) => {
				const n = Ge.unHash(e);
				Array.from(t).forEach((e => {
					this.isOkToHave(n.restrictionType, n.purposeId, e) || t.delete(e)
				}))
			})))
		}
		get gvl() {
			return this.gvl_
		}
		isEmpty() {
			return 0 === this.map.size
		}
		get numRestrictions() {
			return this.map.size
		}
	}(fe = he || (he = {})).COOKIE = "cookie", fe.WEB = "web", fe.APP = "app", (be = ge || (ge = {})).CORE = "core", be.VENDORS_DISCLOSED = "vendorsDisclosed", be.VENDORS_ALLOWED = "vendorsAllowed", be.PUBLISHER_TC = "publisherTC";
	class $e {
		static ID_TO_KEY = [ge.CORE, ge.VENDORS_DISCLOSED, ge.VENDORS_ALLOWED, ge.PUBLISHER_TC];
		static KEY_TO_ID = {
			[ge.CORE]: 0,
			[ge.VENDORS_DISCLOSED]: 1,
			[ge.VENDORS_ALLOWED]: 2,
			[ge.PUBLISHER_TC]: 3
		}
	}
	class We extends ze {
		bitLength = 0;
		maxId_ = 0;
		set_ = new Set;*[Symbol.iterator]() {
			for (let t = 1; t <= this.maxId; t++) yield [t, this.has(t)]
		}
		values() {
			return this.set_.values()
		}
		get maxId() {
			return this.maxId_
		}
		has(t) {
			return this.set_.has(t)
		}
		unset(t) {
			Array.isArray(t) ? t.forEach((t => this.unset(t))) : "object" == typeof t ? this.unset(Object.keys(t).map((t => Number(t)))) : (this.set_.delete(Number(t)), this.bitLength = 0, t === this.maxId && (this.maxId_ = 0, this.set_.forEach((t => {
				this.maxId_ = Math.max(this.maxId, t)
			}))))
		}
		isIntMap(t) {
			let e = "object" == typeof t;
			return e = e && Object.keys(t).every((e => {
				let n = Number.isInteger(parseInt(e, 10));
				return n = n && this.isValidNumber(t[e].id), n = n && void 0 !== t[e].name, n
			})), e
		}
		isValidNumber(t) {
			return parseInt(t, 10) > 0
		}
		isSet(t) {
			let e = !1;
			return t instanceof Set && (e = Array.from(t).every(this.isValidNumber)), e
		}
		set(t) {
			if (Array.isArray(t)) t.forEach((t => this.set(t)));
			else if (this.isSet(t)) this.set(Array.from(t));
			else if (this.isIntMap(t)) this.set(Object.keys(t).map((t => Number(t))));
			else {
				if (!this.isValidNumber(t)) throw new Me("set()", t, "must be positive integer array, positive integer, Set<number>, or IntMap");
				this.set_.add(t), this.maxId_ = Math.max(this.maxId, t), this.bitLength = 0
			}
		}
		empty() {
			this.set_ = new Set;
		}
		forEach(t) {
			for (let e = 1; e <= this.maxId; e++) t(this.has(e), e)
		}
		get size() {
			return this.set_.size
		}
		setAll(t) {
			this.set(t)
		}
	}
	class Qe {
		static[He.cmpId] = 12;
		static[He.cmpVersion] = 12;
		static[He.consentLanguage] = 12;
		static[He.consentScreen] = 6;
		static[He.created] = 36;
		static[He.isServiceSpecific] = 1;
		static[He.lastUpdated] = 36;
		static[He.policyVersion] = 6;
		static[He.publisherCountryCode] = 12;
		static[He.publisherLegitimateInterests] = 24;
		static[He.publisherConsents] = 24;
		static[He.purposeConsents] = 24;
		static[He.purposeLegitimateInterests] = 24;
		static[He.purposeOneTreatment] = 1;
		static[He.specialFeatureOptins] = 12;
		static[He.useNonStandardTexts] = 1;
		static[He.vendorListVersion] = 12;
		static[He.version] = 6;
		static anyBoolean = 1;
		static encodingType = 1;
		static maxId = 16;
		static numCustomPurposes = 6;
		static numEntries = 12;
		static numRestrictions = 12;
		static purposeId = 6;
		static restrictionType = 2;
		static segmentType = 3;
		static singleOrRange = 1;
		static vendorId = 16
	}
	class Je {
		static encode(t) {
			return String(Number(t))
		}
		static decode(t) {
			return "1" === t
		}
	}
	class qe {
		static encode(t, e) {
			let n;
			if ("string" == typeof t && (t = parseInt(t, 10)), n = t.toString(2), n.length > e || t < 0) throw new De(`${t} too large to encode into ${e}`);
			return n.length < e && (n = "0".repeat(e - n.length) + n), n
		}
		static decode(t, e) {
			if (e !== t.length) throw new Re("invalid bit length");
			return parseInt(t, 2)
		}
	}
	class Ye {
		static encode(t, e) {
			return qe.encode(Math.round(t.getTime() / 100), e)
		}
		static decode(t, e) {
			if (e !== t.length) throw new Re("invalid bit length");
			const n = new Date;
			return n.setTime(100 * qe.decode(t, e)), n
		}
	}
	class Ke {
		static encode(t, e) {
			let n = "";
			for (let s = 1; s <= e; s++) n += Je.encode(t.has(s));
			return n
		}
		static decode(t, e) {
			if (t.length !== e) throw new Re("bitfield encoding length mismatch");
			const n = new We;
			for (let s = 1; s <= e; s++) Je.decode(t[s - 1]) && n.set(s);
			return n.bitLength = t.length, n
		}
	}
	class Xe {
		static encode(t, e) {
			const n = (t = t.toUpperCase()).charCodeAt(0) - 65,
				s = t.charCodeAt(1) - 65;
			if (n < 0 || n > 25 || s < 0 || s > 25) throw new De(`invalid language code: ${t}`);
			if (e % 2 == 1) throw new De(`numBits must be even, ${e} is not valid`);
			return qe.encode(n, e /= 2) + qe.encode(s, e)
		}
		static decode(t, e) {
			let n;
			if (e !== t.length || t.length % 2) throw new Re("invalid bit length for language");
			{
				const e = 65,
					s = t.length / 2,
					r = qe.decode(t.slice(0, s), s) + e,
					o = qe.decode(t.slice(s), s) + e;
				n = String.fromCharCode(r) + String.fromCharCode(o)
			}
			return n
		}
	}
	class Ze {
		static encode(t) {
			let e = qe.encode(t.numRestrictions, Qe.numRestrictions);
			if (!t.isEmpty()) {
				const n = Array.from(t.gvl.vendorIds),
					s = (t, e) => {
						const s = n.indexOf(t);
						return n.indexOf(e) - s > 1
					};
				t.getRestrictions().forEach((n => {
					e += qe.encode(n.purposeId, Qe.purposeId), e += qe.encode(n.restrictionType, Qe.restrictionType);
					const r = t.getVendors(n),
						o = r.length;
					let i = 0,
						a = 0,
						p = "";
					for (let t = 0; t < o; t++) {
						const e = r[t];
						if (0 === a && (i++, a = e), t === o - 1 || s(e, r[t + 1])) {
							const t = !(e === a);
							p += Je.encode(t), p += qe.encode(a, Qe.vendorId), t && (p += qe.encode(e, Qe.vendorId)), a = 0
						}
					}
					e += qe.encode(i, Qe.numEntries), e += p
				}))
			}
			return e
		}
		static decode(t) {
			let e = 0;
			const n = new Be,
				s = qe.decode(t.substr(e, Qe.numRestrictions), Qe.numRestrictions);
			e += Qe.numRestrictions;
			for (let r = 0; r < s; r++) {
				const s = qe.decode(t.substr(e, Qe.purposeId), Qe.purposeId);
				e += Qe.purposeId;
				const r = qe.decode(t.substr(e, Qe.restrictionType), Qe.restrictionType);
				e += Qe.restrictionType;
				const o = new Ge(s, r),
					i = qe.decode(t.substr(e, Qe.numEntries), Qe.numEntries);
				e += Qe.numEntries;
				for (let s = 0; s < i; s++) {
					const s = Je.decode(t.substr(e, Qe.anyBoolean));
					e += Qe.anyBoolean;
					const r = qe.decode(t.substr(e, Qe.vendorId), Qe.vendorId);
					if (e += Qe.vendorId, s) {
						const s = qe.decode(t.substr(e, Qe.vendorId), Qe.vendorId);
						if (e += Qe.vendorId, s < r) throw new Re(`Invalid RangeEntry: endVendorId ${s} is less than ${r}`);
						const i = Array.from({
							length: s - r + 1
						}, ((t, e) => r + e));
						n.restrictPurposeToLegalBasis(o, i)
					} else n.restrictPurposeToLegalBasis(o, [r])
				}
			}
			return n.bitLength = e, n
		}
	}(ye = ve || (ve = {}))[ye.FIELD = 0] = "FIELD", ye[ye.RANGE = 1] = "RANGE";
	class tn {
		static encode(t) {
			const e = [];
			let n, s = [],
				r = qe.encode(t.maxId, Qe.maxId),
				o = "";
			const i = Qe.maxId + Qe.encodingType,
				a = i + t.maxId,
				p = 2 * Qe.vendorId + Qe.singleOrRange + Qe.numEntries;
			let l = i + Qe.numEntries;
			return t.forEach(((r, i) => {
				if (o += Je.encode(r), n = t.maxId > p && l < a, n && r) {
					t.has(i + 1) ? 0 === s.length && (s.push(i), l += Qe.singleOrRange, l += Qe.vendorId) : (s.push(i), l += Qe.vendorId, e.push(s), s = [])
				}
			})), n ? (r += String(ve.RANGE), r += this.buildRangeEncoding(e)) : (r += String(ve.FIELD), r += o), r
		}
		static decode(t, e) {
			let n, s = 0;
			const r = qe.decode(t.substr(s, Qe.maxId), Qe.maxId);
			s += Qe.maxId;
			const o = qe.decode(t.charAt(s), Qe.encodingType);
			if (s += Qe.encodingType, o === ve.RANGE) {
				if (n = new We, 1 === e) {
					if ("1" === t.substr(s, 1)) throw new Re("Unable to decode default consent=1");
					s++
				}
				const r = qe.decode(t.substr(s, Qe.numEntries), Qe.numEntries);
				s += Qe.numEntries;
				for (let e = 0; e < r; e++) {
					const e = Je.decode(t.charAt(s));
					s += Qe.singleOrRange;
					const r = qe.decode(t.substr(s, Qe.vendorId), Qe.vendorId);
					if (s += Qe.vendorId, e) {
						const e = qe.decode(t.substr(s, Qe.vendorId), Qe.vendorId);
						s += Qe.vendorId;
						for (let t = r; t <= e; t++) n.set(t)
					} else n.set(r)
				}
			} else {
				const e = t.substr(s, r);
				s += r, n = Ke.decode(e, r)
			}
			return n.bitLength = s, n
		}
		static buildRangeEncoding(t) {
			const e = t.length;
			let n = qe.encode(e, Qe.numEntries);
			return t.forEach((t => {
				const e = 1 === t.length;
				n += Je.encode(!e), n += qe.encode(t[0], Qe.vendorId), e || (n += qe.encode(t[1], Qe.vendorId))
			})), n
		}
	}

	function en() {
		return {
			[He.version]: qe,
			[He.created]: Ye,
			[He.lastUpdated]: Ye,
			[He.cmpId]: qe,
			[He.cmpVersion]: qe,
			[He.consentScreen]: qe,
			[He.consentLanguage]: Xe,
			[He.vendorListVersion]: qe,
			[He.policyVersion]: qe,
			[He.isServiceSpecific]: Je,
			[He.useNonStandardTexts]: Je,
			[He.specialFeatureOptins]: Ke,
			[He.purposeConsents]: Ke,
			[He.purposeLegitimateInterests]: Ke,
			[He.purposeOneTreatment]: Je,
			[He.publisherCountryCode]: Xe,
			[He.vendorConsents]: tn,
			[He.vendorLegitimateInterests]: tn,
			[He.publisherRestrictions]: Ze,
			segmentType: qe,
			[He.vendorsDisclosed]: tn,
			[He.vendorsAllowed]: tn,
			[He.publisherConsents]: Ke,
			[He.publisherLegitimateInterests]: Ke,
			[He.numCustomPurposes]: qe,
			[He.publisherCustomConsents]: Ke,
			[He.publisherCustomLegitimateInterests]: Ke
		}
	}
	class nn {
		1 = {
			[ge.CORE]: [He.version, He.created, He.lastUpdated, He.cmpId, He.cmpVersion, He.consentScreen, He.consentLanguage, He.vendorListVersion, He.purposeConsents, He.vendorConsents]
		};
		2 = {
			[ge.CORE]: [He.version, He.created, He.lastUpdated, He.cmpId, He.cmpVersion, He.consentScreen, He.consentLanguage, He.vendorListVersion, He.policyVersion, He.isServiceSpecific, He.useNonStandardTexts, He.specialFeatureOptins, He.purposeConsents, He.purposeLegitimateInterests, He.purposeOneTreatment, He.publisherCountryCode, He.vendorConsents, He.vendorLegitimateInterests, He.publisherRestrictions],
			[ge.PUBLISHER_TC]: [He.publisherConsents, He.publisherLegitimateInterests, He.numCustomPurposes, He.publisherCustomConsents, He.publisherCustomLegitimateInterests],
			[ge.VENDORS_ALLOWED]: [He.vendorsAllowed],
			[ge.VENDORS_DISCLOSED]: [He.vendorsDisclosed]
		}
	}
	class sn {
		1 = [ge.CORE];
		2 = [ge.CORE];
		constructor(t, e) {
			if (2 === t.version)
				if (t.isServiceSpecific) this[2].push(ge.PUBLISHER_TC);
				else {
					const n = !(!e || !e.isForVendors);
					n && !0 !== t[He.supportOOB] || this[2].push(ge.VENDORS_DISCLOSED), n && (t[He.supportOOB] && t[He.vendorsAllowed].size > 0 && this[2].push(ge.VENDORS_ALLOWED), this[2].push(ge.PUBLISHER_TC))
				}
		}
	}
	class rn {
		static fieldSequence = new nn;
		static encode(t, e) {
			let n;
			try {
				n = this.fieldSequence[String(t.version)][e]
			} catch (n) {
				throw new De(`Unable to encode version: ${t.version}, segment: ${e}`)
			}
			let s = "";
			e !== ge.CORE && (s = qe.encode($e.KEY_TO_ID[e], Qe.segmentType));
			const r = en();
			return n.forEach((n => {
				const o = t[n],
					i = r[n];
				let a = Qe[n];
				void 0 === a && this.isPublisherCustom(n) && (a = Number(t[He.numCustomPurposes]));
				try {
					s += i.encode(o, a)
				} catch (t) {
					throw new De(`Error encoding ${e}->${n}: ${t.message}`)
				}
			})), Ue.encode(s)
		}
		static decode(t, e, n) {
			const s = Ue.decode(t);
			let r = 0;
			n === ge.CORE && (e.version = qe.decode(s.substr(r, Qe[He.version]), Qe[He.version])), n !== ge.CORE && (r += Qe.segmentType);
			const o = this.fieldSequence[String(e.version)][n],
				i = en();
			return o.forEach((t => {
				const n = i[t];
				let o = Qe[t];
				if (void 0 === o && this.isPublisherCustom(t) && (o = Number(e[He.numCustomPurposes])), 0 !== o) {
					const i = s.substr(r, o);
					if (e[t] = n === tn ? n.decode(i, e.version) : n.decode(i, o), Number.isInteger(o)) r += o;
					else {
						if (!Number.isInteger(e[t].bitLength)) throw new Re(t);
						r += e[t].bitLength
					}
				}
			})), e
		}
		static isPublisherCustom(t) {
			return 0 === t.indexOf("publisherCustom")
		}
	}
	class on {
		static processor = [t => t, (t, e) => {
			t.publisherRestrictions.gvl = e, t.purposeLegitimateInterests.unset(1);
			const n = new Map;
			return n.set("legIntPurposes", t.vendorLegitimateInterests), n.set("purposes", t.vendorConsents), n.forEach(((n, s) => {
				n.forEach(((r, o) => {
					if (r) {
						const r = e.vendors[o];
						if (!r || r.deletedDate) n.unset(o);
						else if (0 === r[s].length)
							if ("legIntPurposes" === s && 0 === r.purposes.length && 0 === r.legIntPurposes.length && r.specialPurposes.length > 0) n.set(o);
						    else if ("legIntPurposes" === s && r.purposes.length > 0 && 0 === r.legIntPurposes.length && r.specialPurposes.length > 0) n.set(o);
							else
								if (t.isServiceSpecific){
									if (0 === r.flexiblePurposes.length) n.unset(o);
									else {
										const e = t.publisherRestrictions.getRestrictions(o);
										let r = !1;
										for (let t = 0, n = e.length; t < n && !r; t++) r = e[t].restrictionType === _e.REQUIRE_CONSENT && "purposes" === s || e[t].restrictionType === _e.REQUIRE_LI && "legIntPurposes" === s;
										r || n.unset(o)
									}
								}
						else n.unset(o)
					}
				}))
			})), t.vendorsDisclosed.set(e.vendors), t
		}];
		static process(t, e) {
			const n = t.gvl;
			if (!n) throw new De("Unable to encode TCModel without a GVL");
			if (!n.isReady) throw new De("Unable to encode TCModel tcModel.gvl.readyPromise is not resolved");
			(t = t.clone()).consentLanguage = n.language.slice(0, 2).toUpperCase(), e?.version > 0 && e?.version <= this.processor.length ? t.version = e.version : t.version = this.processor.length;
			const s = t.version - 1;
			if (!this.processor[s]) throw new De(`Invalid version: ${t.version}`);
			return this.processor[s](t, n)
		}
	}
	class an {
		static absCall(t, e, n, s) {
			return new Promise(((r, o) => {
				const i = new XMLHttpRequest;
				i.withCredentials = n, i.addEventListener("load", (() => {
					if (i.readyState == XMLHttpRequest.DONE)
						if (i.status >= 200 && i.status < 300) {
							let t = i.response;
							if ("string" == typeof t) try {
								t = JSON.parse(t)
							} catch (t) {}
							r(t)
						} else o(new Error(`HTTP Status: ${i.status} response type: ${i.responseType}`))
				})), i.addEventListener("error", (() => {
					o(new Error("error"))
				})), i.addEventListener("abort", (() => {
					o(new Error("aborted"))
				})), null === e ? i.open("GET", t, !0) : i.open("POST", t, !0), i.responseType = "json", i.timeout = s, i.ontimeout = () => {
					o(new Error("Timeout " + s + "ms " + t))
				}, i.send(e)
			}))
		}
		static post(t, e, n = !1, s = 0) {
			return this.absCall(t, JSON.stringify(e), n, s)
		}
		static fetch(t, e = !1, n = 0) {
			return this.absCall(t, null, e, n)
		}
	}
	class pn extends ze {
		static LANGUAGE_CACHE = new Map;
		static CACHE = new Map;
		static LATEST_CACHE_KEY = 0;
		static DEFAULT_LANGUAGE = "EN";
		static consentLanguages = new je;
		static baseUrl_;
		static set baseUrl(t) {
			if (/^https?:\/\/vendorlist\.consensu\.org\//.test(t)) throw new Fe("Invalid baseUrl!  You may not pull directly from vendorlist.consensu.org and must provide your own cache");
			t.length > 0 && "/" !== t[t.length - 1] && (t += "/"), this.baseUrl_ = t
		}
		static get baseUrl() {
			return this.baseUrl_
		}
		static latestFilename = "vendor-list.json";
		static versionedFilename = "archives/vendor-list-v[VERSION].json";
		static languageFilename = "purposes-[LANG].json";
		readyPromise;
		gvlSpecificationVersion;
		vendorListVersion;
		tcfPolicyVersion;
		lastUpdated;
		purposes;
		specialPurposes;
		features;
		specialFeatures;
		isReady_ = !1;
		vendors_;
		vendorIds;
		fullVendorList;
		byPurposeVendorMap;
		bySpecialPurposeVendorMap;
		byFeatureVendorMap;
		bySpecialFeatureVendorMap;
		stacks;
		dataCategories;
		lang_;
		cacheLang_;
		googleVendorIds;
		googleVendors_;
		fullGoogleVendorList;
		isLatest = !1;
		constructor(t) {
			super();
			let e = pn.baseUrl;
			if (this.lang_ = pn.DEFAULT_LANGUAGE, this.cacheLang_ = pn.DEFAULT_LANGUAGE, this.isVendorList(t)) this.populate(t), this.readyPromise = Promise.resolve();
			else {
				if (!e) throw new Fe("must specify GVL.baseUrl before loading GVL json");
				if (t > 0) {
					const n = t;
					pn.CACHE.has(n) ? (this.populate(pn.CACHE.get(n)), this.readyPromise = Promise.resolve()) : (e += pn.versionedFilename.replace("[VERSION]", String(n)), this.readyPromise = this.fetchJson(e))
				} else pn.CACHE.has(pn.LATEST_CACHE_KEY) ? (this.populate(pn.CACHE.get(pn.LATEST_CACHE_KEY)), this.readyPromise = Promise.resolve()) : (this.isLatest = !0, this.readyPromise = this.fetchJson(e + pn.latestFilename))
			}
		}
		static emptyLanguageCache(t) {
			let e = !1;
			return null == t && pn.LANGUAGE_CACHE.size > 0 ? (pn.LANGUAGE_CACHE = new Map, e = !0) : "string" == typeof t && this.consentLanguages.has(t.toUpperCase()) && (pn.LANGUAGE_CACHE.delete(t.toUpperCase()), e = !0), e
		}
		static emptyCache(t) {
			let e = !1;
			return Number.isInteger(t) && t >= 0 ? (pn.CACHE.delete(t), e = !0) : void 0 === t && (pn.CACHE = new Map, e = !0), e
		}
		cacheLanguage() {
			pn.LANGUAGE_CACHE.has(this.cacheLang_) || pn.LANGUAGE_CACHE.set(this.cacheLang_, {
				purposes: this.purposes,
				specialPurposes: this.specialPurposes,
				features: this.features,
				specialFeatures: this.specialFeatures,
				stacks: this.stacks,
				dataCategories: this.dataCategories
			})
		}
		async fetchJson(t) {
			try {
				this.populate(await an.fetch(t))
			} catch (t) {
				throw new Fe(t.message)
			}
		}
		getJson() {
			return JSON.parse(JSON.stringify({
				gvlSpecificationVersion: this.gvlSpecificationVersion,
				vendorListVersion: this.vendorListVersion,
				tcfPolicyVersion: this.tcfPolicyVersion,
				lastUpdated: this.lastUpdated,
				purposes: this.purposes,
				specialPurposes: this.specialPurposes,
				features: this.features,
				specialFeatures: this.specialFeatures,
				stacks: this.stacks,
				dataCategories: this.dataCategories,
				vendors: this.fullVendorList,
				googleVendors: this.googleVendors
			}))
		}
		async changeLanguage(t) {
			let e = t;
			try {
				e = pn.consentLanguages.parseLanguage(t)
			} catch (t) {
				throw new Fe("Error during parsing the language: " + t.message)
			}
			const n = t.toUpperCase();
			if ((e.toLowerCase() !== pn.DEFAULT_LANGUAGE.toLowerCase() || pn.LANGUAGE_CACHE.has(n)) && e !== this.lang_)
				if (this.lang_ = e, pn.LANGUAGE_CACHE.has(n)) {
					const t = pn.LANGUAGE_CACHE.get(n);
					for (const e in t) t.hasOwnProperty(e) && (this[e] = t[e])
				} else {
					const t = pn.baseUrl + pn.languageFilename.replace("[LANG]", this.lang_.toLowerCase());
					try {
						await this.fetchJson(t), this.cacheLang_ = n, this.cacheLanguage()
					} catch (t) {
						throw new Fe("unable to load language: " + t.message)
					}
				}
		}
		get language() {
			return this.lang_
		}
		isVendorList(t) {
			return void 0 !== t && void 0 !== t.vendors
		}
		populate(t) {
			this.purposes = t.purposes, this.specialPurposes = t.specialPurposes, this.features = t.features, this.specialFeatures = t.specialFeatures, this.stacks = t.stacks, this.dataCategories = t.dataCategories, this.isVendorList(t) && (this.gvlSpecificationVersion = t.gvlSpecificationVersion, this.tcfPolicyVersion = t.tcfPolicyVersion, this.vendorListVersion = t.vendorListVersion, this.lastUpdated = t.lastUpdated, "string" == typeof this.lastUpdated && (this.lastUpdated = new Date(this.lastUpdated)), this.vendors_ = t.vendors, this.fullVendorList = t.vendors,this.googleVendors_ = t.googleVendors || {},this.fullGoogleVendorList = t.googleVendors || {}, this.mapVendors(), this.mapGoogleVendors(), this.isReady_ = !0, this.isLatest && pn.CACHE.set(pn.LATEST_CACHE_KEY, this.getJson()), pn.CACHE.has(this.vendorListVersion) || pn.CACHE.set(this.vendorListVersion, this.getJson())), this.cacheLanguage()
		}
		mapGoogleVendors(t){
			var h = this;
			if (!this.fullGoogleVendorList || Object.keys(this.fullGoogleVendorList).length === 0) { this.googleVendors_ = {}; this.googleVendorIds = new Set(); return; }
			Array.isArray(t) || (t = Object.keys(this.fullGoogleVendorList).map((function(e) {
				return +e
			}))), this.googleVendors_ = t.reduce((function(e, n) {
				var r = h.googleVendors_[String(n)];
				return r && (e[n] = r), e
			}), {}), this.googleVendorIds = new Set(Object.keys(this.googleVendors_))
		}
		mapVendors(t) {
			this.byPurposeVendorMap = {}, this.bySpecialPurposeVendorMap = {}, this.byFeatureVendorMap = {}, this.bySpecialFeatureVendorMap = {}, Object.keys(this.purposes).forEach((t => {
				this.byPurposeVendorMap[t] = {
					legInt: new Set,
					consent: new Set,
					flexible: new Set
				}
			})), Object.keys(this.specialPurposes).forEach((t => {
				this.bySpecialPurposeVendorMap[t] = new Set
			})), Object.keys(this.features).forEach((t => {
				this.byFeatureVendorMap[t] = new Set
			})), Object.keys(this.specialFeatures).forEach((t => {
				this.bySpecialFeatureVendorMap[t] = new Set
			})), Array.isArray(t) || (t = Object.keys(this.fullVendorList).map((t => +t))), this.vendorIds = new Set(t), this.vendors_ = t.reduce(((t, e) => {
				const n = this.vendors_[String(e)];
				return n && void 0 === n.deletedDate && (n.purposes.forEach((t => {
					this.byPurposeVendorMap[String(t)].consent.add(e)
				})), n.specialPurposes.forEach((t => {
					this.bySpecialPurposeVendorMap[String(t)].add(e)
				})), n.legIntPurposes.forEach((t => {
					this.byPurposeVendorMap[String(t)].legInt.add(e)
				})), n.flexiblePurposes && n.flexiblePurposes.forEach((t => {
					this.byPurposeVendorMap[String(t)].flexible.add(e)
				})), n.features.forEach((t => {
					this.byFeatureVendorMap[String(t)].add(e)
				})), n.specialFeatures.forEach((t => {
					this.bySpecialFeatureVendorMap[String(t)].add(e)
				})), t[e] = n), t
			}), {})
		}
		getFilteredVendors(t, e, n, s) {
			const r = t.charAt(0).toUpperCase() + t.slice(1);
			let o;
			const i = {};
			return o = "purpose" === t && n ? this["by" + r + "VendorMap"][String(e)][n] : this["by" + (s ? "Special" : "") + r + "VendorMap"][String(e)], o.forEach((t => {
				i[String(t)] = this.vendors[String(t)]
			})), i
		}
		getVendorsWithConsentPurpose(t) {
			return this.getFilteredVendors("purpose", t, "consent")
		}
		getVendorsWithLegIntPurpose(t) {
			return this.getFilteredVendors("purpose", t, "legInt")
		}
		getVendorsWithFlexiblePurpose(t) {
			return this.getFilteredVendors("purpose", t, "flexible")
		}
		getVendorsWithSpecialPurpose(t) {
			return this.getFilteredVendors("purpose", t, void 0, !0)
		}
		getVendorsWithFeature(t) {
			return this.getFilteredVendors("feature", t)
		}
		getVendorsWithSpecialFeature(t) {
			return this.getFilteredVendors("feature", t, void 0, !0)
		}
		get vendors() {
			return this.vendors_
		}
		get googleVendors() {
			return this.googleVendors_
		}
		narrowVendorsTo(t) {
			this.mapVendors(t)
		}
		narrowGoogleVendorsTo(t) {
			this.mapGoogleVendors(t)
		}
		get isReady() {
			return this.isReady_
		}
		clone() {
			const t = new pn(this.getJson());
			return this.lang_ !== pn.DEFAULT_LANGUAGE && t.changeLanguage(this.lang_), t
		}
		static isInstanceOf(t) {
			return "object" == typeof t && "function" == typeof t.narrowVendorsTo
		}
	}
	class ln extends ze {
		static consentLanguages = pn.consentLanguages;
		isServiceSpecific_ = !1;
		supportOOB_ = !0;
		useNonStandardTexts_ = !1;
		purposeOneTreatment_ = !1;
		publisherCountryCode_ = "AA";
		version_ = 2;
		consentScreen_ = 0;
		policyVersion_ = 5;
		consentLanguage_ = "EN";
		cmpId_ = 0;
		cmpVersion_ = 0;
		vendorListVersion_ = 0;
		numCustomPurposes_ = 0;
		gvl_;
		created;
		lastUpdated;
		specialFeatureOptins = new We;
		purposeConsents = new We;
		purposeLegitimateInterests = new We;
		publisherConsents = new We;
		publisherLegitimateInterests = new We;
		publisherCustomConsents = new We;
		publisherCustomLegitimateInterests = new We;
		customPurposes;
		vendorConsents = new We;
		vendorLegitimateInterests = new We;
		vendorsDisclosed = new We;
		vendorsAllowed = new We;
		publisherRestrictions = new Be;
		constructor(t) {
			super(), t && (this.gvl = t), this.updated()
		}
		set gvl(t) {
			pn.isInstanceOf(t) || (t = new pn(t)), this.gvl_ = t, this.publisherRestrictions.gvl = t
		}
		get gvl() {
			return this.gvl_
		}
		set cmpId(t) {
			if (t = Number(t), !(Number.isInteger(t) && t > 1)) throw new Me("cmpId", t);
			this.cmpId_ = t
		}
		get cmpId() {
			return this.cmpId_
		}
		set cmpVersion(t) {
			if (t = Number(t), !(Number.isInteger(t) && t > -1)) throw new Me("cmpVersion", t);
			this.cmpVersion_ = t
		}
		get cmpVersion() {
			return this.cmpVersion_
		}
		set consentScreen(t) {
			if (t = Number(t), !(Number.isInteger(t) && t > -1)) throw new Me("consentScreen", t);
			this.consentScreen_ = t
		}
		get consentScreen() {
			return this.consentScreen_
		}
		set consentLanguage(t) {
			this.consentLanguage_ = t
		}
		get consentLanguage() {
			return this.consentLanguage_
		}
		set publisherCountryCode(t) {
			if (!/^([A-z]){2}$/.test(t)) throw new Me("publisherCountryCode", t);
			this.publisherCountryCode_ = t.toUpperCase()
		}
		get publisherCountryCode() {
			return this.publisherCountryCode_
		}
		set vendorListVersion(t) {
			if ((t = Number(t) >> 0) < 0) throw new Me("vendorListVersion", t);
			this.vendorListVersion_ = t
		}
		get vendorListVersion() {
			return this.gvl ? this.gvl.vendorListVersion : this.vendorListVersion_
		}
		set policyVersion(t) {
			if (this.policyVersion_ = parseInt(t, 10), this.policyVersion_ < 0) throw new Me("policyVersion", t)
		}
		get policyVersion() {
			return this.gvl ? this.gvl.tcfPolicyVersion : this.policyVersion_
		}
		set version(t) {
			this.version_ = parseInt(t, 10)
		}
		get version() {
			return this.version_
		}
		set isServiceSpecific(t) {
			this.isServiceSpecific_ = t
		}
		get isServiceSpecific() {
			return this.isServiceSpecific_
		}
		set useNonStandardTexts(t) {
			this.useNonStandardTexts_ = t
		}
		get useNonStandardTexts() {
			return this.useNonStandardTexts_
		}
		set supportOOB(t) {
			this.supportOOB_ = t
		}
		get supportOOB() {
			return this.supportOOB_
		}
		set purposeOneTreatment(t) {
			this.purposeOneTreatment_ = t
		}
		get purposeOneTreatment() {
			return this.purposeOneTreatment_
		}
		setAllVendorConsents() {
			this.vendorConsents.set(this.gvl.vendors)
		}
		unsetAllVendorConsents() {
			this.vendorConsents.empty()
		}
		setAllVendorsDisclosed() {
			this.vendorsDisclosed.set(this.gvl.vendors)
		}
		unsetAllVendorsDisclosed() {
			this.vendorsDisclosed.empty()
		}
		setAllVendorsAllowed() {
			this.vendorsAllowed.set(this.gvl.vendors)
		}
		unsetAllVendorsAllowed() {
			this.vendorsAllowed.empty()
		}
		setAllVendorLegitimateInterests() {
			this.vendorLegitimateInterests.set(this.gvl.vendors)
		}
		unsetAllVendorLegitimateInterests() {
			this.vendorLegitimateInterests.empty()
		}
		setAllPurposeConsents() {
			this.purposeConsents.set(this.gvl.purposes)
		}
		unsetAllPurposeConsents() {
			this.purposeConsents.empty()
		}
		setAllPurposeLegitimateInterests() {
			this.purposeLegitimateInterests.set(this.gvl.purposes)
		}
		unsetAllPurposeLegitimateInterests() {
			this.purposeLegitimateInterests.empty()
		}
		setAllSpecialFeatureOptins() {
			this.specialFeatureOptins.set(this.gvl.specialFeatures)
		}
		unsetAllSpecialFeatureOptins() {
			this.specialFeatureOptins.empty()
		}
		setAll() {
			this.setAllVendorConsents(), this.setAllPurposeLegitimateInterests(), this.setAllSpecialFeatureOptins(), this.setAllPurposeConsents(), this.setAllVendorLegitimateInterests()
		}
		unsetAll() {
			this.unsetAllVendorConsents(), this.unsetAllPurposeLegitimateInterests(), this.unsetAllSpecialFeatureOptins(), this.unsetAllPurposeConsents(), this.unsetAllVendorLegitimateInterests()
		}
		get numCustomPurposes() {
			let t = this.numCustomPurposes_;
			if ("object" == typeof this.customPurposes) {
				const e = Object.keys(this.customPurposes).sort(((t, e) => Number(t) - Number(e)));
				t = parseInt(e.pop(), 10)
			}
			return t
		}
		set numCustomPurposes(t) {
			if (this.numCustomPurposes_ = parseInt(t, 10), this.numCustomPurposes_ < 0) throw new Me("numCustomPurposes", t)
		}
		updated() {
			const t = new Date,
				e = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth(), t.getUTCDate()));
			this.created = e, this.lastUpdated = e
		}
	}
	class dn {
		static encode(t, e) {
			let n, s = "";
			return t = on.process(t, e), n = Array.isArray(e?.segments) ? e.segments : new sn(t, e)["" + t.version], n.forEach(((e, r) => {
				let o = "";
				r < n.length - 1 && (o = "."), s += rn.encode(t, e) + o
			})), s
		}
		static decode(t, e) {
			const n = t.split("."),
				s = n.length;
			e || (e = new ln);
			for (let t = 0; t < s; t++) {
				const s = n[t],
					r = Ue.decode(s.charAt(0)).substr(0, Qe.segmentType),
					o = $e.ID_TO_KEY[qe.decode(r, Qe.segmentType).toString()];
				rn.decode(s, e, o)
			}
			return e
		}
	}
	class cn extends Se {
		respond() {
			const t = ke.tcModel,
				e = t.vendorListVersion;
			let n;
			void 0 === this.param && (this.param = e), n = this.param === e && t.gvl ? t.gvl : new pn(this.param), n.readyPromise.then((() => {
				this.invokeCallback(n.getJson())
			}))
		}
	}
	class un extends Ee {
		respond() {
			this.listenerId = ke.eventQueue.add({
				callback: this.callback,
				param: this.param,
				next: this.next
			}), super.respond()
		}
	}
	class _n extends Se {
		respond() {
			this.invokeCallback(ke.eventQueue.remove(this.param))
		}
	}
	class mn {
		static[oe.PING] = Ae;
		static[oe.GET_TC_DATA] = Ee;
		static[oe.GET_IN_APP_TC_DATA] = Ne;
		static[oe.GET_VENDOR_LIST] = cn;
		static[oe.ADD_EVENT_LISTENER] = un;
		static[oe.REMOVE_EVENT_LISTENER] = _n
	}
	class hn {
		static set_ = new Set([0, 2, void 0, null]);
		static has(t) {
			return "string" == typeof t && (t = Number(t)), this.set_.has(t)
		}
	}
	const fn = "__tcfapi";
	class gn {
		callQueue;
		customCommands;
		constructor(t) {
			if (t) {
				let e = oe.ADD_EVENT_LISTENER;
				if (t?.[e]) throw new Error(`Built-In Custom Commmand for ${e} not allowed: Use ${oe.GET_TC_DATA} instead`);
				if (e = oe.REMOVE_EVENT_LISTENER, t?.[e]) throw new Error(`Built-In Custom Commmand for ${e} not allowed`);
				t?.[oe.GET_TC_DATA] && (t[oe.ADD_EVENT_LISTENER] = t[oe.GET_TC_DATA], t[oe.REMOVE_EVENT_LISTENER] = t[oe.GET_TC_DATA]), this.customCommands = t
			}
			try {
				this.callQueue = window[fn]() || []
			} catch (t) {
				this.callQueue = []
			} finally {
				window[fn] = this.apiCall.bind(this), this.purgeQueuedCalls()
			}
		}
		apiCall(t, e, n, ...s) {
			if ("string" != typeof t) n(null, !1);
			else if (hn.has(e)) {
				if ("function" != typeof n) throw new Error("invalid callback function");
				ke.disabled ? n(new Te, !1) : this.isCustomCommand(t) || this.isBuiltInCommand(t) ? this.isCustomCommand(t) && !this.isBuiltInCommand(t) ? this.customCommands[t](n, ...s) : t === oe.PING ? this.isCustomCommand(t) ? new mn[t](this.customCommands[t], s[0], null, n) : new mn[t](n, s[0]) : void 0 === ke.tcModel ? this.callQueue.push([t, e, n, ...s]) : this.isCustomCommand(t) && this.isBuiltInCommand(t) ? new mn[t](this.customCommands[t], s[0], null, n) : new mn[t](n, s[0]) : n(null, !1)
			} else n(null, !1)
		}
		purgeQueuedCalls() {
			const t = this.callQueue;
			this.callQueue = [], t.forEach((t => {
				window[fn](...t)
			}))
		}
		isCustomCommand(t) {
			return this.customCommands && "function" == typeof this.customCommands[t]
		}
		isBuiltInCommand(t) {
			return void 0 !== mn[t]
		}
	}
	class bn {
		callResponder;
		isServiceSpecific;
		numUpdates = 0;
		constructor(t, e, n = !1, s) {
			this.throwIfInvalidInt(t, "cmpId", 2), this.throwIfInvalidInt(e, "cmpVersion", 0), ke.cmpId = t, ke.cmpVersion = e, ke.tcfPolicyVersion = 5, this.isServiceSpecific = !!n, this.callResponder = new gn(s)
		}
		throwIfInvalidInt(t, e, n) {
			if (!("number" == typeof t && Number.isInteger(t) && t >= n)) throw new Error(`Invalid ${e}: ${t}`)
		}
		update(t, e = !1) {
			if (ke.disabled) throw new Error("CmpApi Disabled");
			ke.cmpStatus = ae.LOADED, e ? (ke.displayStatus = le.VISIBLE, ke.eventStatus = ce.CMP_UI_SHOWN) : void 0 === ke.tcModel ? (ke.displayStatus = le.DISABLED, ke.eventStatus = ce.TC_LOADED) : (ke.displayStatus = le.HIDDEN, ke.eventStatus = ce.USER_ACTION_COMPLETE), (ke.gdprApplies = null !== t) ? ("" === t ? ((ke.tcModel = new ln).cmpId = ke.cmpId, ke.tcModel.cmpVersion = ke.cmpVersion) : ke.tcModel = dn.decode(t), ke.tcModel.isServiceSpecific = this.isServiceSpecific, ke.tcfPolicyVersion = Number(ke.tcModel.policyVersion), ke.tcString = t) : ke.tcModel = null, 0 === this.numUpdates ? this.callResponder.purgeQueuedCalls() : ke.eventQueue.exec(), this.numUpdates++
		}
		disable() {
			ke.disabled = !0, ke.cmpStatus = ae.ERROR
		}
	}
	function _t1() {
		pn.baseUrl = "[WEB_PATH]iab/gvl/";
		pn.latestFilename = "vendor-list-v3.json"
	}
	function e(t, e) {
                return function(t) {
                    if (Array.isArray(t)) return t
                }(t) || function(t, e) {
                    var r = null == t ? null : "undefined" != typeof Symbol && t[Symbol.iterator] || t["@@iterator"];
                    if (null == r) return;
                    var n, o, i = [],
                        s = !0,
                        a = !1;
                    try {
                        for (r = r.call(t); !(s = (n = r.next()).done) && (i.push(n.value), !e || i.length !== e); s = !0);
                    } catch (t) {
                        a = !0, o = t
                    } finally {
                        try {
                            s || null == r.return || r.return()
                        } finally {
                            if (a) throw o
                        }
                    }
                    return i
                }(t, e) || n(t, e) || function() {
                    throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")
                }()
            }

            function n(t, e) {
                if (t) {
                    if ("string" == typeof t) return o(t, e);
                    var r = Object.prototype.toString.call(t).slice(8, -1);
                    return "Object" === r && t.constructor && (r = t.constructor.name), "Map" === r || "Set" === r ? Array.from(t) : "Arguments" === r || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(r) ? o(t, e) : void 0
                }
            }

            function o(t, e) {
                (null == e || e > t.length) && (e = t.length);
                for (var r = 0, n = new Array(e); r < e; r++) n[r] = t[r];
                return n
            }
			function s(t, e, r) {
                return t.replace(e, r)
            }
	var at = null; 
	var a = new Map([
                [".1.", "k"],
                [".2.", "l"],
                [".3.", "m"],
                [".4.", "n"],
                [".5.", "o"],
                [".6.", "p"],
                [".7.", "q"],
                [".8.", "r"],
                [".9.", "s"],
                [".10.", "t"],
                [".11.", "u"],
                ["00", "v"],
                ["k1", "a"],
                ["k2", "b"],
                ["k3", "c"],
                ["k4", "d"],
                ["k5", "e"],
                ["v.", "f"],
                ["12", "w"],
                ["13", "x"],
                ["14", "y"],
                ["15", "z"]
            ]);
				  
	window.conzent = window.conzent || {};
	var cz = window.conzent;	
	cz._cnzStore ={};
	cz._cnzEscapeRegex = function(t) {
		 return t.replace(/[.*+?^${}()[\]\\]/g, "\\$&")
	},
	cz._cnzReplaceAll = function(t, e, r) {
		  return t.replace(new RegExp(cz._cnzEscapeRegex(e), "g"), r)
	}
	cz._cnzEncodeACString = function(t) {
		var r = t.split("~");
		if (!r[1] || t.length < 1200) return t;
		var n = r[1].split(".");
		return r[1] = n.reduce((function(t, e, r) {
			return r > 0 && (t = "".concat(t, ".").concat(Number(e) - Number(n[r - 1]))), t
		}), n[0]), r[1] = Array.from(a.entries()).reduce((function(t, r) {
			var n = e(r, 2),
				o = n[0],
				i = n[1];
			return t.split(o).join(i)
		}), r[1]), r[1] = "_".concat(s(r[1], /(f[0-9]){3,}/g, (function(t) {
			return cz._cnzReplaceAll("G".concat(t, "g"), "f", "")
		}))), r.join("~")
	}, cz._cnzDecodeACString = function(t) {
		var r = t.split("~");
		if (!r[1] || "_" !== r[1][0]) return t;
		r[1] = s(r[1].slice(1), /G([0-9]+)g/g, (function(t) {
			return cz._cnzReplaceAll(t.slice(1, -1), "", "f").slice(0, -1)
		}));
		var n = new Map(Array.from(a, (function(t) {
			return t.reverse()
		})).reverse());
		r[1] = Array.from(n.entries()).reduce((function(t, r) {
			var n = e(r, 2),
				o = n[0],
				i = n[1];
			return t.split(o).join(i)
		}), r[1]);
		var o = r[1].split(".");
		return r[1] = o.reduce((function(t, e, r) {
			return r > 0 && (t = "".concat(t, ".").concat(Number(t.split(".").pop()) + Number(e))), t
		}), o[0]), r.join("~")
	};
	const scrollToElm = (element) => {
    _QS(element).scrollIntoView({
        behavior: `smooth`,
    });
};
	function it_p(e, t, n) {
		return Object.values(e).filter((function(e) {
			return t.includes(e.id)
		})).map((function(e) {
			var t = {
				name: e.name
			};
			return n && (t[n] = n.purposes[e.id] || 0),t
		}))
	}
	function it_r(e, t, n) {
		//console.log(n);
		var tm_new = [];
		
		if(Object.values(n).length > 0){
			
			for(var ik =0;ik<Object.values(n).length;ik++){
				if(Object.keys(n)[ik]){
					var pr_id = Object.keys(n)[ik];
					var dayval = Object.values(n)[ik];
					var t = {
							"name":e[pr_id].name,
							"day":dayval
						};
					tm_new.push(t);	
				}
			}
		}
		return tm_new;
		/*return Object.values(e).filter((function(e) {
			return t.includes(e.id)
		})).map((function(e) {
			
				console.log(n[e.id]);
			
			var t = {
				name: e.name,
				day: n[e.id]||0
			};
			return n && (t[n] = n[e.id] || 0),t
		}))*/
	}
	function GAC(e) {
		cz._addtlConsent = "1~".concat(e.join("."))
	}
	function pnfTabs(){
		cz._pnfTabs = [{
			key: "purposes",
			id: 1,
			toggle: !0,
			sublist: Object.values(cz._tcModal.gvl.purposes).map(ot(1, !0))
		}, {
			key: "special_purposes",
			id: 2,
			toggle: !1,
			sublist: Object.values(cz._tcModal.gvl.specialPurposes).map(ot(2, !1, !1))
		}, {
			key: "features",
			id: 3,
			toggle: !1,
			sublist: Object.values(cz._tcModal.gvl.features).map(ot(3, !1, !1))
		}, {
			key: "special_features",
			id: 4,
			toggle: !0,
			sublist: Object.values(cz._tcModal.gvl.specialFeatures).map(ot(4, !0, !1))
		}], cz._thirdPartyLists = [{
			key: "third_party",
			id: 1,
			toggle: !0,
			sublist: Object.values(cz._tcModal.gvl.vendors).map((function(e) {
				return {
					id: e.id,
					name: e.name,
					privacyLink: e.urls[0].privacy,
					legitimateInterestLink: e.urls[0].legIntClaim,
					hasConsentToggle: !!e.purposes.length,
					hasLegitimateToggle: !!e.legIntPurposes.length,
					totalRetentionPeriod: e.dataRetention.stdRetention || 0,
					dataCategories: it_p(cz._tcModal.gvl.dataCategories, e.dataDeclaration || []),
					purposesForConsent: it_p(cz._tcModal.gvl.purposes, e.purposes, e.dataRetention),
					purposesRetentionPeriod: it_r(cz._tcModal.gvl.purposes, e.purposes, e.dataRetention.purposes||[]),
					specialPurposesRetentionPeriod: it_r(cz._tcModal.gvl.specialPurposes, e.specialPurposes, e.dataRetention.specialPurposes||[]),
					purposesForLegitimateInterest: it_p(cz._tcModal.gvl.purposes, e.legIntPurposes, e.dataRetention),
					specialPurposes: it_p(cz._tcModal.gvl.specialPurposes, e.specialPurposes),
					features: it(cz._tcModal.gvl.features, e.features),
					specialFeatures: it_p(cz._tcModal.gvl.specialFeatures, e.specialFeatures),
					deviceDisclosureURL: e.deviceStorageDisclosureUrl,
					cookieStorageMethod: e.usesNonCookieAccess && e.usesCookies ? "others" : "cookie",
					maximumCookieDuration: e.cookieMaxAgeSeconds / 86400,
					isCookieRefreshed: e.cookieRefresh,
					isGoogleVendor: !1
				}
			}))
		}], cz._thirdPartyLists.push({
			key: "google_ad",
			id: 2,
			toggle: !0,
			sublist: Object.values(cz._tcModal.gvl.googleVendors).map((function(e) {
				return {
					id: e.id,
					name: e.name,
					privacyLink: e.privacy,
					legitimateInterestLink: "",
					hasConsentToggle: !0,
					hasLegitimateToggle: !1,
					totalRetentionPeriod: 0,
					dataCategories: [],
					purposesForConsent: [],
					purposesRetentionPeriod: [],
					specialPurposesRetentionPeriod: [],
					purposesForLegitimateInterest: [],
					specialPurposes: [],
					features: [],
					specialFeatures: [],
					deviceDisclosureURL: "",
					cookieStorageMethod: "",
					maximumCookieDuration: 0,
					isCookieRefreshed: !1,
					isGoogleVendor: !0
				}
			}))
		});
	}
	function createPnfTabs(){
		var pnHtml = [];
		if(cz._pnfTabs){
			cz._pnfTabs.forEach((function(e) {
				if(e.key == 'purposes'){
					var inItem = '', toggleHtml = '', chk_interest = '';
					e.sublist.forEach((function(n) {
						//cnzIABpurpose_tab_section
						var d ='', chk_consent ='', chk_interest = '';
						if (n.illustrations.length > 0) {
							var p = n.illustrations.map((function(e) {
								return "<li>".concat(e, "</li>")
							}));
							d = p.join("");
							d='<div class="cnz-iab-illustrations"><p class="cnz-iab-illustrations-title cnz-sm-heading">[conzent_iab_purpose_n_feature_illustration_subtitle]</p><ul class="cnz-iab-illustrations-des">'+d+'</ul></div>';
						}
						if(n.hasConsentToggle){
							chk_consent='<div class="switch-box">[conzent_iab_common_consent]&nbsp;<span class="conzent-switch chk-all-consent chk-purposes-consent"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABpurpose'+n.id+'ToggleConsent"></span></div>';
						}
						if(n.hasLegitimateToggle){
							chk_interest ='<div class="switch-box">[conzent_iab_common_legitimate_interest]&nbsp;<span class="conzent-switch chk-all-consent chk-purposes-legitimate"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABpurpose'+n.id+'ToggleLegitimate"></span></div>';
						}
						inItem+=''+
							'<div class="conzent-accordion conzent-child-accordion" id="cnzIABpurpose_tab_section'+n.id+'">'+
							'<div class="conzent-accordion-item conzent-child-accordion-item">'+
								'<div class="conzent-accordion-arrow"><i class="cnz-arrow-right"></i></div>'+
								'<div class="conzent-accordion-header-wrapper">'+
								'<div class="conzent-accordion-header">'+
								'<button class="conzent-accordion-btn" data-tab="cnzIABpurpose_tab_section'+n.id+'">'+n.name+'</button><div class="cnz-switch-wrapper">'+chk_interest + chk_consent+'</div></div>'+
								'<div class="conzent-accordion-header-des"></div>'+
								'</div>'+
							'</div>'+
							'<div class="conzent-accordion-body conzent-child-accordion-body">'+
								'<div class="conzent-tab-content"><div id="cnzIABpurpose_tab_section'+n.id+'_section"><div class="iab-ad-details"><p class="iab-ad-details-desc">'+n.userFriendlyText+'</p>'+d+'<p class="cnz-iab-vendors-count-wrapper cnz-sm-heading">'+(n.combinedSeeker ? "[conzent_iab_purpose_n_feature_vendors_seeking_combained]" : "[conzent_iab_purpose_n_feature_vendors_seeking_consent]")+': '+n.seekerCount+'</p></div></div></div>'+
							'</div>'+
							'</div>'+
						'';
					}));
					toggleHtml=e.toggle ? '<div class="switch-box"><span class="conzent-switch chk-all-consent chk-purposes-consent-all"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABpurpose'+e.id+'Toggle"></span></div>': '';

					pnHtml[e.key]=inItem;
					pnHtml[e.key+'_count']= e.sublist.length;
					pnHtml[e.key+'_toggle']= toggleHtml;
				}
				else if(e.key == 'special_purposes'){
					var inItem ='' , toggleHtml = '';
					e.sublist.forEach((function(n) {
						//cnzIABspecial_purpose_tab_section
						var d ='', chk_consent ='', chk_interest = '';
						if (n.illustrations.length > 0) {
							var p = n.illustrations.map((function(e) {
								return "<li>".concat(e, "</li>")
							}));
							d = p.join("");
							d='<div class="cnz-iab-illustrations cnz-sm-heading"><p class="cnz-iab-illustrations-title">[conzent_iab_purpose_n_feature_illustration_subtitle]</p><ul class="cnz-iab-illustrations-des">'+d+'</ul></div>';
						}
						if(n.hasConsentToggle){
							chk_consent='<div class="switch-box">[conzent_iab_common_consent]&nbsp;<span class="conzent-switch chk-all-consent chk-sp-purposes-consent"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABspecial_purpose'+n.id+'ToggleConsent"></span></div>';
						}
						if(n.hasLegitimateToggle){
							chk_interest ='<div class="switch-box">[conzent_iab_common_legitimate_interest]&nbsp;<span class="conzent-switch chk-all-consent chk-sp-purposes-legitimate"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABspecial_purpose'+n.id+'ToggleLegitimate"></span></div>';
						}
						inItem+=''+
							'<div class="conzent-accordion conzent-child-accordion" id="cnzIABspecial_purpose_tab_section'+n.id+'">'+
							'<div class="conzent-accordion-item conzent-child-accordion-item">'+
								'<div class="conzent-accordion-arrow"><i class="cnz-arrow-right"></i></div>'+
								'<div class="conzent-accordion-header-wrapper">'+
								'<div class="conzent-accordion-header">'+
								'<button class="conzent-accordion-btn" data-tab="cnzIABspecial_purpose_tab_section'+n.id+'">'+n.name+'</button><div class="cnz-switch-wrapper">'+chk_interest + chk_consent+'</div></div>'+
								'<div class="conzent-accordion-header-des"></div>'+
								'</div>'+
							'</div>'+
							'<div class="conzent-accordion-body conzent-child-accordion-body">'+
								'<div class="conzent-tab-content"><div id="cnzIABspecial_purpose_tab_section'+n.id+'_section"><div class="iab-ad-details"><p class="iab-ad-details-desc">'+n.userFriendlyText+'</p>'+d+'<p class="cnz-iab-vendors-count-wrapper cnz-sm-heading">'+(n.combinedSeeker ? "[conzent_iab_purpose_n_feature_vendors_seeking_combained]" : "[conzent_iab_purpose_n_feature_vendors_seeking_consent]")+': '+n.seekerCount+'</p></div></div></div>'+
							'</div>'+
							'</div>'+
						'';
					}));
					toggleHtml=e.toggle ? '<div class="switch-box"><span class="conzent-switch chk-all-consent chk-sppurposes-consent-all"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABspecial_purpose'+e.id+'Toggle"></span></div>': '';
					pnHtml[e.key]=inItem;
					pnHtml[e.key+'_count']= e.sublist.length;
					pnHtml[e.key+'_toggle']= toggleHtml;
				}
				else if(e.key == 'features'){
					var inItem ='', toggleHtml = '';
					e.sublist.forEach((function(n) {
						//cnzIABfeatures_tab_section
						var d ='', chk_consent ='', chk_interest = '';
						if (n.illustrations.length > 0) {
							var p = n.illustrations.map((function(e) {
								return "<li>".concat(e, "</li>")
							}));
							d = p.join("");
							d='<div class="cnz-iab-illustrations cnz-sm-heading"><p class="cnz-iab-illustrations-title">[conzent_iab_purpose_n_feature_illustration_subtitle]</p><ul class="cnz-iab-illustrations-des">'+d+'</ul></div>';
						}
						if(n.hasConsentToggle){
							chk_consent='<div class="switch-box">[conzent_iab_common_consent]&nbsp;<span class="conzent-switch chk-all-consent chk-features-consent"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABfeatures'+n.id+'ToggleConsent"></span></div>';
						}
						if(n.hasLegitimateToggle){
							chk_interest ='<div class="switch-box">[conzent_iab_common_legitimate_interest]&nbsp;<span class="conzent-switch chk-all-consent chk-features-legitimate"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABfeatures'+n.id+'ToggleLegitimate"></span></div>';
						}
						inItem+=''+
							'<div class="conzent-accordion conzent-child-accordion" id="cnzIABfeatures_tab_section'+n.id+'">'+
							'<div class="conzent-accordion-item conzent-child-accordion-item">'+
								'<div class="conzent-accordion-arrow"><i class="cnz-arrow-right"></i></div>'+
								'<div class="conzent-accordion-header-wrapper">'+
								'<div class="conzent-accordion-header">'+
								'<button class="conzent-accordion-btn" data-tab="cnzIABfeatures_tab_section'+n.id+'">'+n.name+'</button><div class="cnz-switch-wrapper">'+chk_interest + chk_consent+'</div></div>'+
								'<div class="conzent-accordion-header-des"></div>'+
								'</div>'+
							'</div>'+
							'<div class="conzent-accordion-body conzent-child-accordion-body">'+
								'<div class="conzent-tab-content"><div id="cnzIABfeatures_tab_section'+n.id+'_section"><div class="iab-ad-details"><p class="iab-ad-details-desc">'+n.userFriendlyText+'</p>'+d+'<p class="cnz-iab-vendors-count-wrapper cnz-sm-heading">'+(n.combinedSeeker ? "[conzent_iab_purpose_n_feature_vendors_seeking_combained]" : "[conzent_iab_purpose_n_feature_vendors_seeking_consent]")+': '+n.seekerCount+'</p></div></div></div>'+
							'</div>'+
							'</div>'+
						'';
					}));
					toggleHtml=e.toggle ? '<div class="switch-box"><span class="conzent-switch chk-all-consent chk-features-consent-all"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABfeatures'+e.id+'Toggle"></span></div>': '';
					pnHtml[e.key]=inItem;
					pnHtml[e.key+'_count']= e.sublist.length;
					pnHtml[e.key+'_toggle']= toggleHtml;
				}
				else if(e.key == 'special_features'){
					var inItem ='', toggleHtml = '';
					e.sublist.forEach((function(n) {
						//cnzIABspecial_features_tab_section
						var d ='', chk_consent ='', chk_interest = '';
						if (n.illustrations.length > 0) {
							var p = n.illustrations.map((function(e) {
								return "<li>".concat(e, "</li>")
							}));
							d = p.join("");
							d='<div class="cnz-iab-illustrations"><p class="cnz-iab-illustrations-title cnz-sm-heading">[conzent_iab_purpose_n_feature_illustration_subtitle]</p><ul class="cnz-iab-illustrations-des">'+d+'</ul></div>';
						}
						if(n.hasConsentToggle){
							chk_consent='<div class="switch-box">[conzent_iab_common_consent]&nbsp;<span class="conzent-switch chk-all-consent chk-sp-features-consent"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABspecial_features'+n.id+'ToggleConsent"></span></div>';
						}
						if(n.hasLegitimateToggle){
							chk_interest ='<div class="switch-box">[conzent_iab_common_legitimate_interest]&nbsp;<span class="conzent-switch chk-all-consent chk-sp-features-legitimate"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABspecial_features'+n.id+'ToggleLegitimate"></span></div>';
						}
						inItem+=''+
							'<div class="conzent-accordion conzent-child-accordion" id="cnzIABspecial_features_tab_section'+n.id+'">'+
							'<div class="conzent-accordion-item conzent-child-accordion-item">'+
								'<div class="conzent-accordion-arrow"><i class="cnz-arrow-right"></i></div>'+
								'<div class="conzent-accordion-header-wrapper">'+
								'<div class="conzent-accordion-header">'+
								'<button class="conzent-accordion-btn" data-tab="cnzIABspecial_features_tab_section'+n.id+'">'+n.name+'</button><div class="cnz-switch-wrapper">'+chk_interest + chk_consent+'</div></div>'+
								'<div class="conzent-accordion-header-des"></div>'+
								'</div>'+
							'</div>'+
							'<div class="conzent-accordion-body conzent-child-accordion-body">'+
								'<div class="conzent-tab-content"><div id="cnzIABspecial_features_tab_section'+n.id+'_section"><div class="iab-ad-details"><p class="iab-ad-details-desc">'+n.userFriendlyText+'</p>'+d+'<p class="cnz-iab-vendors-count-wrapper cnz-sm-heading">'+(n.combinedSeeker ? "[conzent_iab_purpose_n_feature_vendors_seeking_combained]" : "[conzent_iab_purpose_n_feature_vendors_seeking_consent]")+': '+n.seekerCount+'</p></div></div></div>'+
							'</div>'+
							'</div>'+
						'';
					}));
					toggleHtml=e.toggle ? '<div class="switch-box"><span class="conzent-switch chk-all-consent chk-sp-features-consent-all"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABspecial_features'+e.id+'Toggle"></span></div>': '';
					pnHtml[e.key]=inItem;
					pnHtml[e.key+'_count']= e.sublist.length;
					pnHtml[e.key+'_toggle']= toggleHtml;
				}			
			}));
		}
		return pnHtml;
	}
	function createVendorTabs(){
		var pnHtml = [];
		if(cz._thirdPartyLists){
			cz._thirdPartyLists.forEach((function(e) {
				if(e.key == 'third_party'){
					var inItem ='', toggleHtml='';
					e.sublist.forEach((function(n) {
					var d ='',sp='',fc='',fsc='', dc ='' , ds ='', dsd ='',chk_consent='',chk_interest='', pur_ret_day='', sp_ret_day='';
						//console.log(n.purposesRetentionPeriod)
						//console.log(n.specialPurposesRetentionPeriod);
						if (n.purposesRetentionPeriod.length > 0) {
							var p = n.purposesRetentionPeriod.map((function(e) {
								if(parseInt(e.day) >0){
									return "<li>"+e.name+" : "+e.day+" [conzent_iab_vendors_retention_period_of_data_unit]</li>";
								}
								else{
									return "";
								}
							}));
							
							pur_ret_day = p.join("");
							if(pur_ret_day.length>0){	
								pur_ret_day='<p class="cnz-sm-heading">[conzent_iab_common_purposes] ([conzent_iab_vendors_retention_period_of_data_subtitle])</p><ul>'+pur_ret_day+'</ul>';
							}	
							
						}
						if (n.specialPurposesRetentionPeriod.length > 0) {
							var p = n.specialPurposesRetentionPeriod.map((function(e) {
								if(parseInt(e.day)>0){
									return "<li>"+e.name+" : "+e.day+" [conzent_iab_vendors_retention_period_of_data_unit]</li>";
								}
								else{
									return "";
								}
							}));
							sp_ret_day = p.join("");
							if(sp_ret_day.length>0){	
								sp_ret_day='<p class="cnz-sm-heading">[conzent_iab_common_special_purposes] ([conzent_iab_vendors_retention_period_of_data_subtitle])</p><ul>'+sp_ret_day+'</ul>';
							}
						}
						if (n.purposesForConsent.length > 0) {
							var p = n.purposesForConsent.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							d = p.join("");
							d='<ul>'+d+'</ul>';
						}
						if (n.specialPurposes.length > 0) {
							var p = n.specialPurposes.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							sp = p.join("");
							sp='<ul>'+sp+'</ul>';
						}
						if (n.features.length > 0) {
							var p = n.features.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							fc = p.join("");
							fc='<ul>'+fc+'</ul>';
						}
						if (n.specialFeatures.length > 0) {
							var p = n.specialFeatures.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							fsc = p.join("");
							fsc='<ul>'+fsc+'</ul>';
						}
						if (n.dataCategories.length > 0) {
							var p = n.dataCategories.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							dc = p.join("");
							dc='<div><p class="cnz-sm-heading">[conzent_iab_vendors_categories_of_data_subtitle]</p><ul>'+dc+'</ul></div>';
						}
						if (n.cookieStorageMethod.length > 0) {
							ds='<div>'+
							'<p class="cnz-sm-heading">[conzent_iab_vendors_device_storage_overview_title]</p>'+
							'<ul>'+
								'<li>[conzent_iab_vendors_tracking_method_subtitle]: '+("cookie" === n.cookieStorageMethod ? "[conzent_iab_vendors_tracking_method_cookie_message]" : "[conzent_iab_vendors_tracking_method_others_message]")+'</li>'+
								'<li>[conzent_iab_vendors_maximum_duration_of_cookies_subtitle]: '+n.maximumCookieDuration+' [conzent_iab_vendors_maximum_duration_of_cookies_unit]</li>'+
								'<li>'+(n.isCookieRefreshed ? "[conzent_iab_vendors_cookie_refreshed_message]" : "[conzent_iab_vendors_cookie_not_refreshed_message]")+'</li>'+
							'</ul></div>';
						}
						/*if (n.purposesForConsent.length > 0) {
							var p = n.purposesForConsent.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							dsd = p.join("");
							dsd='<ul>'+dsd+'</ul>';
						}*/
						if(n.hasConsentToggle){
							chk_consent='<div class="switch-box">[conzent_iab_common_consent]&nbsp;<span class="conzent-switch chk-all-consent chk-vendors chk-vendor-thirdparty chk-vendor-consent"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABvendors_thirdparty'+n.id+'ToggleConsent"></span></div>';
						}
						if(n.hasLegitimateToggle){
							chk_interest ='<div class="switch-box">[conzent_iab_common_legitimate_interest]&nbsp;<span class="conzent-switch chk-all-consent chk-vendors chk-vendor-thirdparty chk-vendor-legitimate"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABvendors_thirdparty'+n.id+'ToggleLegitimate"></span></div>';
						}
						
						inItem+=''+
							'<div class="conzent-accordion conzent-child-accordion" id="cnzIABvendors_thirdparty_section'+n.id+'">'+
							'<div class="conzent-accordion-item conzent-child-accordion-item">'+
								'<div class="conzent-accordion-arrow"><i class="cnz-arrow-right"></i></div>'+
								'<div class="conzent-accordion-header-wrapper">'+
								'<div class="conzent-accordion-header">'+
								'<button class="conzent-accordion-btn" data-tab="cnzIABvendors_thirdparty_section'+n.id+'">'+n.name+'</button> <div class="cnz-switch-wrapper">'+chk_interest + chk_consent+'</div></div>'+
								'<div class="conzent-accordion-header-des"></div>'+
								'</div>'+
							'</div>'+
							'<div class="conzent-accordion-body conzent-child-accordion-body">'+
								'<div class="conzent-tab-content"><div id="cnzIABvendors_thirdparty_section'+n.id+'_section"><div class="iab-ad-details">'+
								'<p class="cnz-sm-heading">[conzent_iab_vendors_privacy_policy_link_subtitle]: <a href="'+n.privacyLink+'" target="_blank">'+n.privacyLink+'</a></p>'+
								'<p class="cnz-sm-heading">[conzent_iab_vendors_legitimate_interest_link_subtitle]: <a href="'+n.legitimateInterestLink+'">'+n.legitimateInterestLink+'</a></p>'+
								'<p class="cnz-sm-heading">[conzent_iab_vendors_retention_period_of_data_subtitle]: '+n.totalRetentionPeriod+' [conzent_iab_vendors_retention_period_of_data_unit]</p>'+pur_ret_day + sp_ret_day+''+
								'<div><p class="cnz-sm-heading">[conzent_iab_common_purposes] ([conzent_iab_common_consent])</p>'+d+'</div>'
								+sp+fc+fsc+dc+ds+dsd+
								'</div></div></div>'+
							'</div>'+
							'</div>'+
						'';
					}));
					toggleHtml = e.toggle ? '<div class="switch-box"><span class="conzent-switch chk-all-consent chk-allvendor-thirdparty"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABvendors_thirdparty'+e.id+'Toggle"></span></div>': '';
					
					pnHtml[e.key]=inItem;
					pnHtml[e.key+'_count']= e.sublist.length;
					pnHtml[e.key+'_toggle']= toggleHtml;
				}
				else if(e.key == 'google_ad'){
					var inItem ='',toggleHtml='';
					e.sublist.forEach((function(n) {
						
					var d ='',sp='',fc='',fsc='', dc ='' , ds ='', dsd ='',chk_consent='',chk_interest='';
					
						if (n.purposesForConsent.length > 0) {
							var p = n.purposesForConsent.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							d = p.join("");
							d='<ul>'+d+'</ul>';
						}
						if (n.specialPurposes.length > 0) {
							var p = n.specialPurposes.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							sp = p.join("");
							sp='<ul>'+sp+'</ul>';
						}
						if (n.features.length > 0) {
							var p = n.features.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							fc = p.join("");
							fc='<ul>'+fc+'</ul>';
						}
						if (n.specialFeatures.length > 0) {
							var p = n.specialFeatures.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							fsc = p.join("");
							fsc='<ul>'+fsc+'</ul>';
						}
						if (n.dataCategories.length > 0) {
							var p = n.dataCategories.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							dc = p.join("");
							dc='<ul>'+dc+'</ul>';
						}
						if (n.cookieStorageMethod.length > 0) {
							ds='<div>'+
							'<p class="cnz-sm-heading">[conzent_iab_vendors_device_storage_overview_title]</p>'+
							'<ul>'+
								'<li>[conzent_iab_vendors_tracking_method_subtitle]: '+("cookie" === n.cookieStorageMethod ? "[conzent_iab_vendors_tracking_method_cookie_message]" : "[conzent_iab_vendors_tracking_method_others_message]")+'</li>'+
								'<li>[conzent_iab_vendors_maximum_duration_of_cookies_subtitle]: '+n.maximumCookieDuration+' [conzent_iab_vendors_maximum_duration_of_cookies_unit]</li>'+
								'<li>'+(n.isCookieRefreshed ? "[conzent_iab_vendors_cookie_refreshed_message]" : "[conzent_iab_vendors_cookie_not_refreshed_message]")+'</li>'+
							'</ul></div>';
							ds ='';
						}
						/*if (n.purposesForConsent.length > 0) {
							var p = n.purposesForConsent.map((function(e) {
								return "<li>".concat(e.name, "</li>")
							}));
							dsd = p.join("");
							dsd='<ul>'+dsd+'</ul>';
						}*/
						if(n.hasConsentToggle){
							chk_consent='<div class="switch-box">[conzent_iab_common_consent]&nbsp;<span class="conzent-switch chk-all-consent chk-vendors chk-vendor-google chk-vendor-consent"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABvendors_google_ad'+n.id+'ToggleConsent"></span></div>';
						}
						if(n.hasLegitimateToggle){
							chk_interest ='<div class="switch-box">[conzent_iab_common_legitimate_interest]&nbsp;<span class="conzent-switch chk-all-consent chk-vendors chk-vendor-google chk-vendor-legitimate"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABvendors_google_ad'+n.id+'ToggleLegitimate"></span></div>';
						}
						inItem+=''+
							'<div class="conzent-accordion conzent-child-accordion" id="cnzIABvendors_google_ad_section'+n.id+'">'+
							'<div class="conzent-accordion-item conzent-child-accordion-item">'+
								'<div class="conzent-accordion-arrow"><i class="cnz-arrow-right"></i></div>'+
								'<div class="conzent-accordion-header-wrapper">'+
								'<div class="conzent-accordion-header">'+
								'<button class="conzent-accordion-btn" data-tab="cnzIABvendors_google_ad_section'+n.id+'">'+n.name+'</button> <div class="cnz-switch-wrapper">'+chk_interest + chk_consent+'</div></div>'+
								'<div class="conzent-accordion-header-des"></div>'+
								'</div>'+
							'</div>'+
							'<div class="conzent-accordion-body conzent-child-accordion-body">'+
								'<div class="conzent-tab-content"><div id="cnzIABvendors_google_ad_section'+n.id+'_section"><div class="iab-ad-details">'+
								'<p class="cnz-sm-heading">[conzent_iab_vendors_privacy_policy_link_subtitle]: <a href="'+n.privacyLink+'" target="_blank">'+n.privacyLink+'</a></p>'
								+sp+fc+fsc+dc+ds+dsd+
								'</div></div></div>'+
							'</div>'+
							'</div>'+
						'';
													
				}));
					toggleHtml = e.toggle ? '<div class="switch-box"><span class="conzent-switch chk-all-consent chk-allvendor-google"><input type="checkbox" value="1" class="sliding-switch" id="cnzIABvendors_google_ad'+e.id+'Toggle"></span></div>':'';
					pnHtml[e.key]=inItem;
					pnHtml[e.key+'_count']= e.sublist.length;
					pnHtml[e.key+'_toggle']= toggleHtml;
				}
			}));	
		}		
		return pnHtml;
	}
	
	function updateTcf(es){
		var n = CNZ_config.settings.default_laws, cz_action = cookieExists("conzentConsent");
		var tcf_data = ("gdpr" === n ? es && cz_action ? "" : cz._cnzStore._tcStringValue : null);
		cz._cmpAPI.update(tcf_data, es);
	}
	function saveTcf(e,cl){
		var es = !0;
		if(e == 'all'){
			
			cz._tcModal.purposeLegitimateInterests.set([2, 7, 8, 9, 10, 11]),
			cz._tcModal.setAllPurposeConsents(), 
			cz._tcModal.setAllSpecialFeatureOptins(), 
			cz._tcModal.setAllVendorLegitimateInterests(), 
			cz._tcModal.setAllVendorConsents(),
			GAC(Array.from(cz._tcModal.gvl.googleVendorIds)),
			es = !1;
			checkAllIabConsent(true);
			
		}else if(e =='custom' && cl == 'gdpr'){
			cz._tcModal.unsetAll();
			GAC([]);
			
			var n_purpose = getElm('cnzIABpurpose',cz._pnfTabs[0].sublist),
				n_spurpose = getElm('cnzIABspecial_features',cz._pnfTabs[3].sublist),
				n_vendors = getElm('cnzIABvendors_thirdparty',cz._thirdPartyLists[0].sublist),
				n_google_vendors = getElm('cnzIABvendors_google_ad',cz._thirdPartyLists[1].sublist);
			var o = n_vendors[1],
				i = n_vendors[0],
				c = n_purpose[0],
				a = n_purpose[1],
				u = n_spurpose[0],
				l = n_google_vendors[0];
			cz._tcModal.vendorConsents.set(i),			
			cz._tcModal.vendorLegitimateInterests.set(o), 
			cz._tcModal.purposeLegitimateInterests.set(a), 
			cz._tcModal.purposeConsents.set(c), 
			cz._tcModal.specialFeatureOptins.set(u), GAC(l),
			es = !1;
		}
		else{
			
			cz._tcModal.unsetAll();
			checkAllIabConsent(false);
			GAC([]);
			es = !1;
		}
		const o1 = dn.encode(cz._tcModal,{
                        segments: [ge.CORE, ge.VENDORS_DISCLOSED, ge.PUBLISHER_TC]
                    });
		cz._cnzStore._tcStringValue = o1;
		Conzent_Cookie.set("euconsent", "".concat(cz._cnzStore._tcStringValue, ",").concat(cz._cnzEncodeACString(cz._addtlConsent || "")),CNZ_config.settings.expires,1);
		updateTcf(es);
	}
	function getElm(e,t){
		var r = [],
			o = [];
		return t.forEach((function(t) {
			var i = _QS("#".concat(e).concat(t.id, "ToggleConsent"));
			i && i.checked && o.push(t.id);
			var c = _QS("#".concat(e).concat(t.id, "ToggleLegitimate"));
			c && c.checked && r.push(t.id)
		})), [o, r]	
	}
	function checkedElms(e,t,m){
		if(t.allowed.length>0){
			t.allowed.forEach((function(t) {
				var elm = _QS("#".concat(e).concat(t, m));
					if(elm){
				    	elm.checked = true;
					}
			}))
		}
		if(t.rejected.length>0){
			t.rejected.forEach((function(t) {
				var elm = _QS("#".concat(e).concat(t, m));
				    if(elm){
						elm.checked = false;
					}
			}))	
		}
	}
	function checkedElm(e, t ,m){
		if(_QS("#".concat(e).concat(m))){
			_QS("#".concat(e).concat(m)).checked = t;
		}
		
	}
	function _QS(el){
		return document.querySelector(el);	
	}
	function _QSA(el){
		return document.querySelectorAll(el);	
	}
	function checkAllIabConsent(ck_checked){
		_QSA('.chk-all-consent .sliding-switch').forEach(ele => {							   
			ele.checked = ck_checked;
		});		
	}
	function loadIabelements(){
		var chk_th = _QS(".chk-allvendor-thirdparty .sliding-switch"),
		chk_vn = _QS(".chk-allvendor-google .sliding-switch"),
		chk_pc = _QS(".chk-purposes-consent-all .sliding-switch"),
		chk_spc = _QS(".chk-sp-purposes-consent-all .sliding-switch"),
		chk_fc = _QS(".chk-features-consent-all .sliding-switch"),
		chk_sfc = _QS(".chk-sp-features-consent-all .sliding-switch");
		
		if(_QS("#cnzIABNoticeButton")){
			_QS("#cnzIABNoticeButton").addEventListener("click",function(){
				if(_QS("#cnzIABTabVendor")) _QS("#cnzIABTabVendor").click();
				_QS("#cookieSettings").click();
				
			});
		}
		if(_QS("#cnzIABPreferenceButton")){
			_QS("#cnzIABPreferenceButton").addEventListener("click",function(){
				if(_QS("#cnzIABTabVendor")) _QS("#cnzIABTabVendor").click();
				scrollToElm('#cnzIABvendors_thirdparty');
				if(_QS("#cnzIABvendors_thirdparty")) _QS("#cnzIABvendors_thirdparty").classList.add('cnz-active');
					
			});
		}
		if(_QS("#cnzIABGACMPreferenceButton")){
			_QS("#cnzIABGACMPreferenceButton").addEventListener("click",function(){
				if(_QS("#cnzIABTabVendor")) _QS("#cnzIABTabVendor").click();
				scrollToElm('#cnzIABvendors_google_ad');				
				if(_QS("#cnzIABvendors_google_ad")) _QS("#cnzIABvendors_google_ad").classList.add('cnz-active');
			});
		}
		
		if(chk_th){
			chk_th.addEventListener("click",function(){
					var ck_checked = this.checked;
					_QSA('.chk-vendor-thirdparty .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});								 
			});
		}
		if(chk_vn){
			chk_vn.addEventListener("click",function(){
					var ck_checked = this.checked;
					_QSA('.chk-vendor-google .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});									 
			});
		}
		if(chk_pc){
			chk_pc.addEventListener("click",function(){
				var ck_checked = this.checked;
					_QSA('.chk-purposes-consent .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});
					_QSA('.chk-purposes-legitimate .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});
			});
		}
		if(chk_spc){
			chk_spc.addEventListener("click",function(){
				var ck_checked = this.checked;
					_QSA('.chk-sp-purposes-consent .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});
					_QSA('.chk-sp-purposes-legitimate .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});									 
			});
		}
		if(chk_fc){
			chk_fc.addEventListener("click",function(){
				var ck_checked = this.checked;
					_QSA('.chk-features-consent .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});
					_QSA('.chk-features-legitimate .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});										 
			});
		}
		if(chk_sfc){
			chk_sfc.addEventListener("click",function(){
				var ck_checked = this.checked;
					_QSA('.chk-sp-features-consent .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});
					_QSA('.chk-sp-features-legitimate .sliding-switch').forEach(ele => {							   
						ele.checked = ck_checked;
					});										 
			});
		}
		
		markSelectedEle();
	}
	function markSelectedEle(){
		var t = _et(), 
			n = _tt(), 
			r = _nt(), 
			o = _rt();
		checkedElm('cnzIABvendors_thirdparty1',t.sectionChecked,'Toggle');
		checkedElms('cnzIABvendors_thirdparty',t.consent,'ToggleConsent');
		checkedElms('cnzIABvendors_thirdparty',t.legitimateInterest,'ToggleLegitimate');
		
		checkedElm('cnzIABvendors_google_ad2',n.sectionChecked,'Toggle');
		checkedElms('cnzIABvendors_google_ad',n.consent,'ToggleConsent');
		
		checkedElm('cnzIABpurpose1',r.sectionChecked,'Toggle');
		checkedElms('cnzIABpurpose',r.consent,'ToggleConsent');
		checkedElms('cnzIABpurpose',r.legitimateInterest,'ToggleLegitimate');
		
		checkedElm('cnzIABspecial_features4',o.sectionChecked,'Toggle');
		checkedElms('cnzIABspecial_features',o.consent,'ToggleConsent');
		
	}
	function loadIabtcf(){

		if(CNZ_config.settings.iab_support == 1){
			_t1();
			var l_n = (Conzent_Cookie.read('euconsent') || "").split(",");
			Object.assign(cz._cnzStore, {
					_prevTCString: l_n[0] || "",
					_prevGoogleACMString: cz._cnzDecodeACString(l_n[1] || ""),
					_tcStringValue: ""
			});
			const ct = {};
			var at = null,
				t = null,
				n = cz._cnzStore,
				r = n._prevTCString,
				o = n._prevGoogleACMString;
			if(r){
				if([CNCMPID]!=dn.decode(r).cmpId){
					Conzent_Cookie.erase("euconsent",1);
					r = '';
					o = '';
				}
			}
			if(CNZ_config.settings.additional_gcm == 1){
				cz._addtlConsent = o;
			}
			else{
				cz._addtlConsent = '1~';	
			}
			
			at = pt(new bn([CNCMPID],[CNCMPVERSION], !0, {
				getTCData: (t, e, n) => {
					"boolean" != typeof e && (e.addtlConsent = cz._addtlConsent, e.enableAdvertiserConsentMode = window?.conzent.Configs?.google_consent), t(e, n)
				}
			}));
			cz._cmpAPI = at;	
			_t1();	
			const u = new ln(new pn(st.vendorListVersion));
			u.cmpId = [CNCMPID], u.cmpVersion = [CNCMPVERSION], u.consentScreen = 1, u.consentLanguage = CNZ_config.currentLang.toUpperCase().replace("_","-") || "EN", u.gvl.lang_ = CNZ_config.currentLang.toUpperCase().replace("_","-") || "EN", u.isServiceSpecific = !0, u.publisherCountryCode = CNZ_config.publisherCountry.toUpperCase()||"AA", u.gvl.readyPromise.then((() => {
				var new_tcModel = u;
				new_tcModel.purposeLegitimateInterests.set([2, 7, 8, 9, 10, 11]);
				new_tcModel.setAllVendorLegitimateInterests();
				r ? ((t = dn.decode(r)).policyVersion_ < u.tcfPolicyVersion ? (new_tcModel, Conzent_Cookie.set("euconsent", "", 0,1)) : (t.gvl = u.gvl, cz._cnzStore._tcStringValue = r), o && (cz._addtlConsent = o)) : t = u, cz._tcModal = t;
				pnfTabs();
				// Replace {vendor_count} with actual GVL vendor count in the notice
				var _vc = Object.keys(t.gvl.vendors || {}).length + Object.keys(t.gvl.googleVendors || {}).length;
				var _nb = _QS('#cnzIABNoticeButton');
				if (_nb) { _nb.textContent = _nb.textContent.replace('{vendor_count}', _vc); }
				var _nd = _QS('.conzent-iab-detail-wrapper');
				if (_nd) { _nd.innerHTML = _nd.innerHTML.replace(/\{vendor_count\}/g, _vc); }
				var _cn = _QS('#Conzent');
				if (_cn) { _cn.innerHTML = _cn.innerHTML.replace(/\{vendor_count\}/g, _vc); }
				if(CNZ_config.settings.additional_gcm == 1){
					window['gtag_enable_tcf_support'] = true;
					window.iabConfig = {
						allowedVendors: CNZ_config.settings.allowedVendors,
						allowedGoogleVendors: CNZ_config.settings.allowedGoogleVendors,
					}
				}
				if(window.iabConfig){
					if(Object.keys(window.iabConfig.allowedVendors).length>0){
						t.gvl.narrowVendorsTo(window.iabConfig.allowedVendors)
					}
					if(Object.keys(window.iabConfig.allowedGoogleVendors).length>0){
						t.gvl.narrowGoogleVendorsTo(window.iabConfig.allowedGoogleVendors)
					}
				}
				Conzent.init();
				var cz_act = cookieExists("conzentConsent");
				updateTcf(!cz_act);
				
		
			}));
		}	
	}
	function loadIabtcf_preview(){

		if(CNZ_config.settings.iab_support == 1){
			_t1();
			const ct = {};
			var at = null,
				t = null,
				n = '',
				r = '',
				o = '';
			if(r){
				if([CNCMPID]!=dn.decode(r).cmpId){
					r = '';
					o = '';
				}
			}
			if(CNZ_config.settings.additional_gcm == 1){
				cz._addtlConsent = o;
			}
			else{
				cz._addtlConsent = '1~';	
			}
			
			at = pt(new bn([CNCMPID],[CNCMPVERSION], !0, {
				getTCData: (t, e, n) => {
					"boolean" != typeof e && (e.addtlConsent = cz._addtlConsent, e.enableAdvertiserConsentMode = window?.conzent.Configs?.google_consent), t(e, n)
				}
			}));
			cz._cmpAPI = at;	
			_t1();	
			const u = new ln(new pn(st.vendorListVersion));
			u.cmpId = [CNCMPID], u.cmpVersion = [CNCMPVERSION], u.consentScreen = 1, u.consentLanguage = CNZ_config.currentLang.toUpperCase() || "EN", u.gvl.lang_ = CNZ_config.currentLang.toUpperCase() || "EN", u.isServiceSpecific = !0, u.publisherCountryCode = CNZ_config.publisherCountry.toUpperCase()||"AA", u.gvl.readyPromise.then((() => {
				var new_tcModel = u;
				new_tcModel.purposeLegitimateInterests.set([2, 7, 8, 9, 10, 11]);
				new_tcModel.setAllVendorLegitimateInterests();
				r ? ((t = dn.decode(r)).policyVersion_ < u.tcfPolicyVersion ? (new_tcModel) : (t.gvl = u.gvl), o ) : t = u, cz._tcModal = t;
				pnfTabs();
				// Replace {vendor_count} with actual GVL vendor count
				var _vc = Object.keys(t.gvl.vendors || {}).length + Object.keys(t.gvl.googleVendors || {}).length;
				var _cn = _QS('#Conzent');
				if (_cn) { _cn.innerHTML = _cn.innerHTML.replace(/\{vendor_count\}/g, _vc); }
				Conzent.init();
			}));
		}	
	}
