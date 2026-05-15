export interface StoredConversation {
	id: string;
	title: string;
	messages: Array< { id: number; role: 'user' | 'assistant'; content: string } >;
	updatedAt: number;
}
