export interface IdeaBoardNote {
	id: string;
	type: 'insight' | 'idea' | 'question';
	title: string;
	body: string;
	colour: 'yellow' | 'pink' | 'green' | 'blue' | 'orange' | 'lime' | 'white';
	x: number;
	y: number;
	rotation: number;
	prompt: string;
	confidence: 'low' | 'medium' | 'high';
}

export interface IdeaBoardArrow {
	from: string;
	to: string;
	label: string;
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
	notes: IdeaBoardNote[];
	arrows: IdeaBoardArrow[];
	content: {
		source: 'ai';
	};
	layout: {
		source: 'ai' | 'fallback';
		board: {
			width: number;
			height: number;
		};
		card: {
			width: number;
			height: number;
			gap: number;
		};
	};
	generatedAt: string;
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
	| { status: 'error'; message: string };

export type IdeaBoardStatus = 'idle' | 'loading' | 'reanalysing' | 'error';
