export type IdeaBoardStage = 'insights' | 'investigate' | 'context' | 'proposed_actions';

export type IdeaBoardCardKind = 'insight' | 'question' | 'context' | 'action';

export type IdeaBoardCardColour = 'yellow' | 'pink' | 'green' | 'blue' | 'orange' | 'lime' | 'white';

export type IdeaBoardConfidence = 'low' | 'medium' | 'high';

export type IdeaBoardRevenueLever =
	| 'traffic'
	| 'conversion'
	| 'aov'
	| 'retention'
	| 'margin'
	| 'inventory'
	| 'pricing'
	| 'campaign_spend'
	| 'catalogue_quality'
	| 'revenue_protection';

export type IdeaBoardSeverity = 'low' | 'medium' | 'high' | 'critical';

export type IdeaBoardGatePriority = 'required' | 'useful' | 'optional';

export type IdeaBoardAnswerability = 'ai' | 'merchant' | 'both';

export type IdeaBoardActionType =
	| 'investigate'
	| 'merchandise'
	| 'restock'
	| 'pause_campaign'
	| 'create_offer'
	| 'improve_product_content'
	| 'retention_campaign'
	| 'pricing_test'
	| 'checkout_fix'
	| 'customer_follow_up';

export type IdeaBoardCardStatus =
	| 'new'
	| 'merchant_input_needed'
	| 'reviewed'
	| 'draft'
	| 'approval_required'
	| 'accepted';

export type IdeaBoardSessionStatus = 'active' | 'ready_to_reanalyse' | 'analysed';

export type IdeaBoardNoteKind = 'note' | 'answer' | 'context';

export interface IdeaBoardDecisionBrief {
	whatChanged: string;
	commercialWhy: string;
	biggestUnknowns: string;
	bestNextMove: string;
	upsideRisk: string;
}

export interface IdeaBoardEvidenceLink {
	label: string;
	url: string;
}

export interface IdeaBoardEvidenceDetails {
	metricBaseline: string;
	comparisonPeriod: string;
	involvedProducts: string;
	involvedOrders: string;
	involvedCustomers: string;
	confidenceReason: string;
	dataFreshness: string;
	unknowns: string;
	wooLinks: IdeaBoardEvidenceLink[];
}

export interface IdeaBoardColumn {
	id: IdeaBoardStage;
	title: string;
	description: string;
}

export interface IdeaBoardCard {
	id: string;
	kind: IdeaBoardCardKind;
	stage: IdeaBoardStage;
	title: string;
	body: string;
	colour: IdeaBoardCardColour;
	order: number;
	prompt: string;
	confidence: IdeaBoardConfidence;
	evidence: string;
	evidenceDetails: IdeaBoardEvidenceDetails;
	timeframe: string;
	source: string;
	status: IdeaBoardCardStatus;
	approvalRequired: boolean;
	revenueLevers: IdeaBoardRevenueLever[];
	severity: IdeaBoardSeverity;
	estimatedImpact: string;
	whyItMatters: string;
	relatedSignalIds: string[];
	gatePriority: IdeaBoardGatePriority;
	answerability: IdeaBoardAnswerability;
	actionType: IdeaBoardActionType;
	expectedRevenueImpact: IdeaBoardConfidence;
	effort: IdeaBoardConfidence;
	timeToImpact: string;
	riskApprovalNeeded: string;
	primaryMetric: string;
	owner: string;
	reviewDate: string;
	successCriteria: string;
	iceScore: number;
	rootInsightId: string;
	rootInsightIds: string[];
	sessionId: string;
	parentCardId: string;
	createdBy: 'ai' | 'merchant';
	x: number;
	y: number;
	rotation: number;
}

export interface IdeaBoardLink {
	from: string;
	to: string;
	label: string;
}

export interface IdeaBoardSession {
	id: string;
	title: string;
	rootInsightIds: string[];
	summary: string;
	decisionBrief: IdeaBoardDecisionBrief;
	status: IdeaBoardSessionStatus;
	createdAt: string;
	updatedAt: string;
	lastAnalysedAt: string;
}

export interface IdeaBoardNote {
	id: string;
	sessionId: string;
	rootInsightIds: string[];
	parentCardId: string;
	body: string;
	kind: IdeaBoardNoteKind;
	createdBy: 'ai' | 'merchant';
	authorName: string;
	createdAt: string;
}

export interface IdeaBoardData {
	id: string;
	title: string;
	period: {
		start: string;
		end: string;
		label: string;
		days: number;
		comparison: string;
	};
	currency: string;
	headlineMetrics: {
		net_sales: number;
		orders_count: number;
		average_order_value: number;
		total_customers: number;
	};
	columns: IdeaBoardColumn[];
	cards: IdeaBoardCard[];
	links: IdeaBoardLink[];
	sessions: IdeaBoardSession[];
	notes: IdeaBoardNote[];
	summary: string;
	decisionBrief: IdeaBoardDecisionBrief;
	content: {
		source: 'ai';
	};
	layout: {
		source: 'columns' | 'sessions';
	};
	generatedAt: string;
	/** Server-authoritative timestamp of the last successful save. Sent back on saves for optimistic concurrency. */
	savedAt?: string;
	freshness?: {
		isStale: boolean;
		currentPeriod: {
			start: string;
			end: string;
			label: string;
			days: number;
		};
		savedPeriod: {
			start: string;
			end: string;
			label: string;
			days: number;
		};
	};
}

export type IdeaBoardResponse =
	| { status: 'ok'; board: IdeaBoardData }
	| { status: 'error'; message: string; code?: string };

export type IdeaBoardStatus = 'idle' | 'loading' | 'saving' | 'brainstorming' | 'reanalysing' | 'answering' | 'error';
