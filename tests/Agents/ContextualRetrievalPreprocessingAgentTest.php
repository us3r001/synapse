<?php

declare(strict_types=1);

    use Saloon\Http\Faking\MockClient;
    use Saloon\Http\Faking\MockResponse;
    use UseTheFork\Synapse\Integrations\Connectors\OpenAI\Requests\ChatRequest;
    use UseTheFork\Synapse\Templates\ContextualRetrievalPreprocessingAgent;

    it('can run the Knowledge Graph Extraction Agent.', function () {

    MockClient::global([
        ChatRequest::class => MockResponse::fixture("agents/ContextualRetrievalPreprocessingAgent"),
    ]);

    $agent = new ContextualRetrievalPreprocessingAgent;

    $agentResponse = $agent->handle([
        'document' => "//! Executor for differential fuzzing.\n//! It wraps two executors that will be run after each other with the same input.\n//! In comparison to the [`crate::executors::CombinedExecutor`] it also runs the secondary executor in `run_target`.\n//!\nuse core::{cell::UnsafeCell, fmt::Debug, ptr};\n\nuse libafl_bolts::{ownedref::OwnedMutPtr, tuples::MatchName};\nuse serde::{Deserialize, Serialize};\n\nuse crate::{\n    executors::{Executor, ExitKind, HasObservers},\n    inputs::UsesInput,\n    observers::{DifferentialObserversTuple, ObserversTuple, UsesObservers},\n    state::UsesState,\n    Error,\n};\n\n/// A [`DiffExecutor`] wraps a primary executor, forwarding its methods, and a secondary one\n#[derive(Debug)]\npub struct DiffExecutor<A, B, OTA, OTB, DOT> {\n    primary: A,\n    secondary: B,\n    observers: UnsafeCell<ProxyObserversTuple<OTA, OTB, DOT>>,\n}\n\nimpl<A, B, OTA, OTB, DOT> DiffExecutor<A, B, OTA, OTB, DOT> {\n    /// Create a new `DiffExecutor`, wrapping the given `executor`s.\n    pub fn new(primary: A, secondary: B, observers: DOT) -> Self\n    where\n        A: UsesState + HasObservers<Observers = OTA>,\n        B: UsesState<State = A::State> + HasObservers<Observers = OTB>,\n        DOT: DifferentialObserversTuple<OTA, OTB, A::State>,\n        OTA: ObserversTuple<A::State>,\n        OTB: ObserversTuple<A::State>,\n    {\n        Self {\n            primary,\n            secondary,\n            observers: UnsafeCell::new(ProxyObserversTuple {\n                primary: OwnedMutPtr::Ptr(ptr::null_mut()),\n                secondary: OwnedMutPtr::Ptr(ptr::null_mut()),\n                differential: observers,\n            }),\n        }\n    }\n\n    /// Retrieve the primary `Executor` that is wrapped by this `DiffExecutor`.\n    pub fn primary(&mut self) -> &mut A {\n        &mut self.primary\n    }\n\n    /// Retrieve the secondary `Executor` that is wrapped by this `DiffExecutor`.\n    pub fn secondary(&mut self) -> &mut B {\n        &mut self.secondary\n    }\n}\n\nimpl<A, B, EM, DOT, Z> Executor<EM, Z> for DiffExecutor<A, B, A::Observers, B::Observers, DOT>\nwhere\n    A: Executor<EM, Z> + HasObservers,\n    B: Executor<EM, Z, State = A::State> + HasObservers,\n    EM: UsesState<State = A::State>,\n    DOT: DifferentialObserversTuple<A::Observers, B::Observers, A::State>,\n    Z: UsesState<State = A::State>,\n{\n    fn run_target(\n        &mut self,\n        fuzzer: &mut Z,\n        state: &mut Self::State,\n        mgr: &mut EM,\n        input: &Self::Input,\n    ) -> Result<ExitKind, Error> {\n        self.observers(); // update in advance\n        let observers = self.observers.get_mut();\n        observers\n            .differential\n            .pre_observe_first_all(observers.primary.as_mut())?;\n        observers.primary.as_mut().pre_exec_all(state, input)?;\n        let ret1 = self.primary.run_target(fuzzer, state, mgr, input)?;\n        observers\n            .primary\n            .as_mut()\n            .post_exec_all(state, input, &ret1)?;\n        observers\n            .differential\n            .post_observe_first_all(observers.primary.as_mut())?;\n        observers\n            .differential\n            .pre_observe_second_all(observers.secondary.as_mut())?;\n        observers.secondary.as_mut().pre_exec_all(state, input)?;\n        let ret2 = self.secondary.run_target(fuzzer, state, mgr, input)?;\n        observers\n            .secondary\n            .as_mut()\n            .post_exec_all(state, input, &ret2)?;\n        observers\n            .differential\n            .post_observe_second_all(observers.secondary.as_mut())?;\n        if ret1 == ret2 {\n            Ok(ret1)\n        } else {\n            // We found a diff in the exit codes!\n            Ok(ExitKind::Diff {\n                primary: ret1.into(),\n                secondary: ret2.into(),\n            })\n        }\n    }\n}\n\n/// Proxy the observers of the inner executors\n#[derive(Serialize, Deserialize, Debug)]\n#[serde(\n    bound = \"A: serde::Serialize + serde::de::DeserializeOwned, B: serde::Serialize + serde::de::DeserializeOwned, DOT: serde::Serialize + serde::de::DeserializeOwned\"\n)]\npub struct ProxyObserversTuple<A, B, DOT> {\n    primary: OwnedMutPtr<A>,\n    secondary: OwnedMutPtr<B>,\n    differential: DOT,\n}\n\nimpl<A, B, DOT, S> ObserversTuple<S> for ProxyObserversTuple<A, B, DOT>\nwhere\n    A: ObserversTuple<S>,\n    B: ObserversTuple<S>,\n    DOT: DifferentialObserversTuple<A, B, S>,\n    S: UsesInput,\n{\n    fn pre_exec_all(&mut self, state: &mut S, input: &S::Input) -> Result<(), Error> {\n        self.differential.pre_exec_all(state, input)\n    }\n\n    fn post_exec_all(\n        &mut self,\n        state: &mut S,\n        input: &S::Input,\n        exit_kind: &ExitKind,\n    ) -> Result<(), Error> {\n        self.differential.post_exec_all(state, input, exit_kind)\n    }\n\n    fn pre_exec_child_all(&mut self, state: &mut S, input: &S::Input) -> Result<(), Error> {\n        self.differential.pre_exec_child_all(state, input)\n    }\n\n    fn post_exec_child_all(\n        &mut self,\n        state: &mut S,\n        input: &S::Input,\n        exit_kind: &ExitKind,\n    ) -> Result<(), Error> {\n        self.differential\n            .post_exec_child_all(state, input, exit_kind)\n    }\n\n    /// Returns true if a `stdout` observer was added to the list\n    #[inline]\n    fn observes_stdout(&self) -> bool {\n        self.primary.as_ref().observes_stdout() || self.secondary.as_ref().observes_stdout()\n    }\n    /// Returns true if a `stderr` observer was added to the list\n    #[inline]\n    fn observes_stderr(&self) -> bool {\n        self.primary.as_ref().observes_stderr() || self.secondary.as_ref().observes_stderr()\n    }\n\n    /// Runs `observe_stdout` for all stdout observers in the list\n    fn observe_stdout(&mut self, stdout: &[u8]) {\n        self.primary.as_mut().observe_stderr(stdout);\n        self.secondary.as_mut().observe_stderr(stdout);\n    }\n\n    /// Runs `observe_stderr` for all stderr observers in the list\n    fn observe_stderr(&mut self, stderr: &[u8]) {\n        self.primary.as_mut().observe_stderr(stderr);\n        self.secondary.as_mut().observe_stderr(stderr);\n    }\n}\n\nimpl<A, B, DOT> MatchName for ProxyObserversTuple<A, B, DOT>\nwhere\n    A: MatchName,\n    B: MatchName,\n    DOT: MatchName,\n{\n    fn match_name<T>(&self, name: &str) -> Option<&T> {\n        if let Some(t) = self.primary.as_ref().match_name::<T>(name) {\n            Some(t)\n        } else if let Some(t) = self.secondary.as_ref().match_name::<T>(name) {\n            Some(t)\n        } else {\n            self.differential.match_name::<T>(name)\n        }\n    }\n    fn match_name_mut<T>(&mut self, name: &str) -> Option<&mut T> {\n        if let Some(t) = self.primary.as_mut().match_name_mut::<T>(name) {\n            Some(t)\n        } else if let Some(t) = self.secondary.as_mut().match_name_mut::<T>(name) {\n            Some(t)\n        } else {\n            self.differential.match_name_mut::<T>(name)\n        }\n    }\n}\n\nimpl<A, B, DOT> ProxyObserversTuple<A, B, DOT> {\n    fn set(&mut self, primary: &A, secondary: &B) {\n        self.primary = OwnedMutPtr::Ptr(ptr::from_ref(primary) as *mut A);\n        self.secondary = OwnedMutPtr::Ptr(ptr::from_ref(secondary) as *mut B);\n    }\n}\n\nimpl<A, B, OTA, OTB, DOT> UsesObservers for DiffExecutor<A, B, OTA, OTB, DOT>\nwhere\n    A: HasObservers<Observers = OTA>,\n    B: HasObservers<Observers = OTB, State = A::State>,\n    OTA: ObserversTuple<A::State>,\n    OTB: ObserversTuple<A::State>,\n    DOT: DifferentialObserversTuple<OTA, OTB, A::State>,\n{\n    type Observers = ProxyObserversTuple<OTA, OTB, DOT>;\n}\n\nimpl<A, B, OTA, OTB, DOT> UsesState for DiffExecutor<A, B, OTA, OTB, DOT>\nwhere\n    A: UsesState,\n    B: UsesState<State = A::State>,\n{\n    type State = A::State;\n}\n\nimpl<A, B, OTA, OTB, DOT> HasObservers for DiffExecutor<A, B, OTA, OTB, DOT>\nwhere\n    A: HasObservers<Observers = OTA>,\n    B: HasObservers<Observers = OTB, State = A::State>,\n    OTA: ObserversTuple<A::State>,\n    OTB: ObserversTuple<A::State>,\n    DOT: DifferentialObserversTuple<OTA, OTB, A::State>,\n{\n    #[inline]\n    fn observers(&self) -> &ProxyObserversTuple<OTA, OTB, DOT> {\n        unsafe {\n            self.observers\n                .get()\n                .as_mut()\n                .unwrap()\n                .set(self.primary.observers(), self.secondary.observers());\n            self.observers.get().as_ref().unwrap()\n        }\n    }\n\n    #[inline]\n    fn observers_mut(&mut self) -> &mut ProxyObserversTuple<OTA, OTB, DOT> {\n        unsafe {\n            self.observers\n                .get()\n                .as_mut()\n                .unwrap()\n                .set(self.primary.observers(), self.secondary.observers());\n            self.observers.get().as_mut().unwrap()\n        }\n    }\n}\n",
        'chunk' => "//! Executor for differential fuzzing.\n//! It wraps two executors that will be run after each other with the same input.\n//! In comparison to the [`crate::executors::CombinedExecutor`] it also runs the secondary executor in `run_target`.\n//!\nuse core::{cell::UnsafeCell, fmt::Debug, ptr};\n\nuse libafl_bolts::{ownedref::OwnedMutPtr, tuples::MatchName};\nuse serde::{Deserialize, Serialize};\n\nuse crate::{\n    executors::{Executor, ExitKind, HasObservers},\n    inputs::UsesInput,\n    observers::{DifferentialObserversTuple, ObserversTuple, UsesObservers},\n    state::UsesState,\n    Error,\n};\n\n/// A [`DiffExecutor`] wraps a primary executor, forwarding its methods, and a secondary one\n#[derive(Debug)]\npub struct DiffExecutor<A, B, OTA, OTB, DOT> {\n    primary: A,\n    secondary: B,\n    observers: UnsafeCell<ProxyObserversTuple<OTA, OTB, DOT>>,\n}\n\n"
                                    ]);

    expect($agentResponse)->toBeArray()
        ->and($agentResponse)->toHaveKey('succinct_context')
        ->and($agentResponse['succinct_context'] == 'Introduction and definition for DiffExecutor, a differential fuzzing executor utilizing two separate executors, with detailed struct definitions involving state management and observer usage.')->toBeTrue();
});
