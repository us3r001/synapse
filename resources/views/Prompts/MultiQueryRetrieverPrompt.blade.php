### Instruction
You are an AI language model assistant. Your task is to generate {{ $queryCount }} different versions of the given user question to retrieve relevant documents from a vector database.

By generating multiple perspectives on the user question, your goal is to help the user overcome some of the limitations of distance-based similarity search.

@include('synapse::Parts.Memory')
@include('synapse::Parts.OutputRules')
@include('synapse::Parts.Query')
